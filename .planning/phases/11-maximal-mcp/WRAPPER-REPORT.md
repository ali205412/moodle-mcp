# Wrapper / Catalog / Discovery Report (wrapper-fixer)

Scope: `classes/local/tool_provider.php`, `classes/local/catalog/*`, `classes/local/discovery/*`,
`classes/local/wrapper/*`, their tests, one appended cache definition in `db/caches.php`, appended lang strings,
and the `version.php` bump to 2026101002 (registers the `mcp_visibility` cache on upgrade). Nothing committed.

## Round 1: audit findings

1. **wrapper_moodle_api_execute** (`wrapper/discovery_service.php`):
   - Signature: `manager::execute($name, $args, context $restrictedcontext, ?stdClass $user = null, ?int $serviceid = null)`.
   - Rejection checks:
     - Unknown functions are rejected.
     - Deprecated functions are rejected.
     - Functions not in an enabled `external_services_functions` row for the service are rejected; this is checked against the DB at call time, and when the service id is null the function must be in some enabled service.
     - Functions not visible to the user per the eligibility rules are rejected.
   - Delegates to `external_api::call_external_function()`; I checked it is identical in 4.2 and 4.5. There is no `validate_context(system)` first.
   - Throws `moodle_exception('wrapper:apiexecutefailed')`, with `$e->a = {functionname, errorcode, message}` carrying Moodle's own error code and message.
   - Returns `{functionname, type, data}`.
   - Throws `coding_exception` if the user passed in is not the global `$USER`.
2. **wrapper_moodle_api_search**:
   - Searches the catalog snapshot using the same visibility rules as tools/list.
   - Matching is tokenised and case-insensitive.
   - Limit is 25 by default, 100 at most.
   - An empty query returns a summary by domain and component.
   - Each hit has name, component, domain, type, `readOnly`, description (300 characters or less) and risk.

   New `wrapper_moodle_api_describe`:
   - Takes 1 to 20 names.
   - Returns the full description, input and output schemas, required capabilities, risk, `requiredParams` and `exampleArgs`.
   - Names that are out of scope come back in an `unavailable` list.
3. **Read-only flag**: `manager::is_mutating($name, $args)` uses the explicit `readOnlyHint` on each definition. The read-only wrappers are search, describe, preview, module_read_data and memory_read. For `api_execute`, a call is mutating unless the target function is declared type read. `definition` gained `annotations` and `title`; `api_execute` has `destructiveHint` true.
4. **Restricted-context escapes**:
   - `activity_service::add_module`:
     - Rejects reserved option keys.
     - Validates the course context.
     - New arguments `section`, `visible`, `intro`, `introformat`.
     - `cmidnumber` is checked with `grade_verify_idnumber`.
     - `groupingid` must belong to the course.
     - `lang` requires `moodle/course:setforcedlanguage`.
   - Question bank:
     - Every touched context is validated in delete, move and update_category.
     - Move uses the `move` capability on each source question plus `add` on the target, as qbank_bulkmove does.
5. **Library loading**: `questionlib`, `gradelib` and `course/lib.php` are loaded in every public method that needs them.
6. **schema_builder**:
   - Types and fields:
     - INT maps to `integer`, FLOAT to `number`, BOOL to `boolean`.
     - `allownull` adds `"null"` to the type.
     - `VALUE_DEFAULT` becomes `default`.
     - Required structures are listed in `required`.
     - Descriptions are carried through.
     - Input objects get `additionalProperties:false`.
   - New `build_output()` is permissive in the ways Moodle's returned values actually are:
     - string-like values may also be numbers or booleans;
     - structures whose keys are all optional also accept an empty `[]`;
     - a null returns description accepts any JSON.
   - A test validates a real `get_site_info` result against its generated schema.
   - Every wrapper output schema now matches what the wrapper returns, and ids are integers.
7. **Badges and gradebook**:
   - Updating an active or locked badge is rejected.
   - `award_badge` checks the recipient: real, not deleted, not suspended, not a guest, and able to earn the badge (`is_enrolled` for course badges, `has_capability` for site badges).
   - A `false` return from gradebook `set_parent` now throws, and the parent category must be in the same course.
   - `delete_questions` reports `deletedquestionids` and `hiddenquestionids`.
8. **tool_provider**: connector mode no longer evaluates native entries unless asked to. Groups and coverage are consistent. Tool names may be up to 128 characters of `[A-Za-z0-9_-]`.
9. **Memory**:
   - New wrappers: `wrapper_memory_read`, `wrapper_memory_update`, `wrapper_memory_delete`.
   - Limits: 64 KB per memory, 500 per user.
   - No system context check; access is ownership plus not being a guest, so restricted tokens work.
10. **Eligibility performance**: the access-information index is built once per request instead of being searched for every entry.
11. **Booleans**: `arguments::to_bool()` treats "false", "0", "off", "no" and "" as false. JSON booleans already pass Moodle's `validate_parameters` for PARAM_BOOL, so no coercion was needed for `api_execute`.
12. **read_module_data** is now a permission-checked module inspector:
    - It returns cm info, visibility, availability, completion and the formatted intro.
    - Only users who can manage activities also get the instance settings (secrets removed) and the file areas.
13. **CI hygiene**: headers, PHPDoc, strict types and `@covers` everywhere.

## Round 2: "do everything"

1. **Risk never hides or blocks anything**:
   - `eligibility_resolver` no longer reads `showhighrisktools` and has no risk branch.
   - Risk is calculated only for entries that are already visible, as information.
   - Search, describe and execute all follow the same rule.
   - I updated the `wrapper:apifunctionhidden` wording.
2. **Caching** (`discovery/visibility_cache.php`, MUC `mcp_visibility`, TTL 300s, static acceleration):
   - **What is cached:**
     - Per-key visible sets: names plus risk and eligibility metadata, not schemas.
     - Search results per (visibility key, query, limit, component, type).
   - **Who uses it:** tools/list, `api_search`, `api_describe` and the `api_execute` visibility pre-check. Execution itself always goes through the call-time service check and Moodle's own checks.
   - **Purging:** happens whenever the catalog snapshot is rebuilt or invalidated.
   - **Key**: sha1 of
     - user id;
     - restricted context id;
     - sorted service ids (or "any");
     - catalog snapshot signature;
     - connector mode;
     - a permission marker.

     The permission marker is sha1 of:
     - `MAX(cache_flags.timemodified)` for `accesslib/dirtycontexts`, set by capability and override changes and `context->mark_dirty()`;
     - the user's `accesslib/dirtyusers` flag time, set by role assign and unassign;
     - the user's `role_assignments` count, max id and max time;
     - the count, max id and max time across all of `role_capabilities`;
     - `$CFG->siteadmins`;
     - `$CFG->defaultuserroleid`.

     Gap: changing an existing permission value twice within the same second is not detected; the 300s TTL covers it.
3. **File splits**:
   - `question_bank_service` is now a thin wrapper keeping the same public methods, delegating to:
     - `question_category_service`
     - `question_service`
     - `question_form_builder`
     - `question_import_service`
   - `badge_record_builder` was split out of `badge_service`.
   - `workflow_descriptors` was split out of `wrapper_registry`. The data is identical to before (I verified it), so the catalog signature does not change.
   - `catalog/tool_metadata` was split out of `tool_provider`.
   - `gateway_definitions` was split out of `builtin_definitions`.
   - Every file I own is now 600 lines or less.
4. **Tool name length**: a test with synthetic catalog entries shows 65- and 128-character names are listed, while a 129-character name and an invalid character set are dropped.
5. **`exposenativetools`**: when enabled, native functions are listed after the wrappers. Their annotations:
   - `readOnlyHint` and `idempotentHint` are true when the function is type read;
   - `destructiveHint` is true for write functions with a destructive name or a capability carrying data-loss risk;
   - `openWorldHint` is false.

   Catalog entries carry the same annotations; I bumped the catalog format version to 3 so cached snapshots rebuild.
6. **Search and describe**:
   - Search now also matches capability strings and parameter names at any depth.
   - Filters:
     - `component` matches the component or a function-name prefix, because core functions report component `moodle`.
     - `type` filters on read or write.
   - Hits include `requiredParams` and `exampleArgs` when the limit is 10 or less.

## Tests

- Round 2, Moodle 4.5: `OK (238 tests, 1564 assertions)`.
- Round 2, Moodle 4.2: `Tests: 238, Assertions: 1557, Errors: 1`. The one error is `jwt_bearer_grant_test::test_valid_assertion_issues_tokens`, which is in auth-fixer's OAuth code, not mine.
- The missing `use stdClass` in `question_import_service` that files-builder spotted was already fixed before these runs.
- After bumping `version.php` to 2026101002 I re-ran Moodle 4.2: `Tests: 244, Assertions: 1586, Errors: 1, Failures: 1`. Both failures are in the new `mcp_tasks_test` (`test_legacy_task_lifecycle`, `test_modern_task_via_cron`), which is the team lead's in-flight tasks work. Every test for my files passed.

## For the transport / settings owners

- The transport's current calls to `is_mutating($name, $args)` and `execute(..., (int)$this->restricted_serviceid)` already match.
- Optional: `error_result` could expose `$e->a->errorcode` from `wrapper:apiexecutefailed` as `org.moodle/errorcode`.
- `showhighrisktools` is now ignored. Suggested description: "Deprecated and ignored: risk is informational only and never hides or blocks tools. Access is limited by the user's Moodle permissions, the connector service and the token's context."

## Open decision

Discovery, and therefore the `api_execute` pre-check, still hides a function when the user lacks any capability listed for it in its `db/services.php` `capabilities` field, as long as that capability can be checked in the restricted context. Those lists often mean "capabilities this function may use", not "all of these are required". For example, `core_user_get_users` lists `moodle/user:update`, so a teacher can't see or call it through the gateway even though Moodle itself would allow the call. Making this check informational too would match the "only real Moodle permissions" directive, but I left it unchanged until you decide.

## Deliberately not done

- Lang strings were appended at the end of the file, not in alphabetical order.

## Round 3: declared capabilities are informational only

- **`eligibility_resolver`**: only login can hide an entry now.
  - A declared `db/services.php` capability the user lacks in the restricted context no longer hides the function.
  - Instead it is recorded in `eligibility.missingCapabilities`, and `eligibility.likelyPermitted` is set to false.
  - The visibility cache stores this metadata; the cache key is unchanged.
- **`api_execute` pre-check**: what remains is service membership (checked against the DB at call time) and login. Restricted context and permissions are enforced by Moodle inside the function.
  - `wrapper:apifunctionhidden` now only means "requires a logged-in Moodle user".
- **tools/list** (including `exposenativetools`): every tool in the service is listed. `x-moodle.likelyPermitted` is set and `x-moodle.eligibility.missingCapabilities` lists the gaps. Coverage `visibleTools` counts them all.
- **`api_search` and `api_describe`**:
  - Every hit and description has `likelyPermitted` and `missingCapabilities`.
  - Search sorts likely-permitted hits first, then by matched words, score and name.
  - The search tool description explains that `likelyPermitted=false` may still work.
- **Tests**:
  - A teacher (editingteacher in a course, with the token restricted to that course) finds `core_user_get_users` with `likelyPermitted` false, then executes it and gets their student back.
  - A plain user sees `core_user_create_users` in search with `likelyPermitted` false and `moodle/user:create` in `missingCapabilities`; likely-permitted hits come first.
  - tools/list lists `mod_lti_get_tool_proxies` with `likelyPermitted` false.
  - The cache test now checks that `likelyPermitted` changes from false to true after a capability is granted to a role the user already holds.

### Round 3 test results

- Moodle 4.2: `Tests: 246, Assertions: 1599, Errors: 1, Failures: 1`. Both are in `mcp_tasks_test` (team lead's in-flight tasks work).
- Moodle 4.5: `Tests: 247, Assertions: 1608, Failures: 1`. The failure is `oauth_service_test::test_refresh_replay_revokes_family` (auth-fixer's area).
- Every test for my files passed on both versions.

### Note for the tasks work

`execute_api` uses `external_api::call_external_function()`. Outside a web service request (`WS_SERVER` false, e.g. cron), that function requires a sesskey for login-required functions, so `wrapper_moodle_api_execute` run from a cron task would fail. If tasks are meant to run gateway calls, `execute_api` could switch to `tool_runner::call_function()`, which keeps the original exceptions; I haven't done this because `tool_runner.php` is the team lead's file.

## Round 4: gateway runs through tool_runner; libraries loaded explicitly

- **`execute_api`** now calls `\webservice_mcp\local\mcp\tool_runner::call_function($name, $params)` instead of `external_api::call_external_function()`. That runs the same steps as the web service server (validate, plugin overrides, call, clean) but without the sesskey/session checks, so `wrapper_moodle_api_execute` behaves the same over HTTP and in cron.
  - These checks still run first: session user, function exists, not deprecated, service membership checked against the DB, and login.
  - The context restriction is set by `manager::execute()`. Restricted context and capabilities are enforced inside the function by `validate_context()` and `require_capability()`.
  - Any exception is rethrown as `moodle_exception('wrapper:apiexecutefailed')`.
    - `$e->a` holds `{functionname, errorcode, message}`. `errorcode` is the original exception's `errorcode`, or its class name if it isn't a `moodle_exception`.
    - The original `debuginfo` is kept, so it shows only when developer debugging is on.
- **Library loading check**: I went through every public wrapper and discovery entry point to make sure it loads what it uses, instead of relying on libraries something else loaded earlier:
  - `activity_service`
    - `add_module` loads `course/lib.php` (which pulls in filelib and completionlib), `course/modlib.php` and `lib/gradelib.php`.
    - `read_module_data` loads `course/lib.php`; this covers `file_rewrite_pluginfile_urls` (used by `format_module_intro`) and `completion_info`.
  - `course_authoring_service`: every public method loads `course/lib.php`, either directly or through `get_course()`, which every method calls first.
  - `gradebook_service`: every public method loads `lib/gradelib.php`; `update_category` also loads `grade/edit/tree/lib.php`.
  - `badge_service`: every public method loads `lib/badgeslib.php`; award and revoke also load `badges/lib/awardlib.php`.
  - Question services (`question_category_service`, `question_service`, `question_import_service`) now load `lib/filelib.php` next to `lib/questionlib.php` in every public method. Saving and importing questions uses draft-file functions, and `questionlib.php` does not load filelib itself. Import also loads `question/format.php` and the format plugin file.
  - `memory_service`, `discovery_service`, `api_search` and the catalog/discovery classes only use always-loaded core APIs and autoloaded classes; `tool_runner` loads `pagelib.php`.

### Round 4 test results

- Moodle 4.2: `OK (251 tests, 1638 assertions)`
- Moodle 4.5: `OK (252 tests, 1651 assertions)`

## Round 5: final-review fixes (FINAL-REVIEW.md)

- **#2 HIGH, badge XSS** (`badge_record_builder.php`):
  - Badge fields are cleaned the way `badges/classes/form/badge.php` cleans them:
    - PARAM_TEXT: name (must not be empty), version, imageauthorname, imagecaption.
    - PARAM_NOTAGS: description, issuername.
    - PARAM_URL plus a required `http(s)://` prefix: imageauthorurl, issuerurl.
    - `validate_email`: imageauthoremail, issuercontact.
    - The language must be an installed language; tags are cleaned with PARAM_TAG.
    - Expiry is validated: `expiry 1` needs a future date, `expiry 2` needs a positive period.
  - Message fields follow the message form and `update_message()`: PARAM_TEXT subject and `clean_text` for the message.
  - Alignment fields follow `badges/alignment_form.php`:
    - PARAM_TEXT targetname, targetframework and targetcode.
    - PARAM_URL targeturl with an http(s) prefix required; `javascript:` and similar are rejected.
    - targetdescription is cleaned with PARAM_NOTAGS.
    - targetname and targeturl are required.
- **#12**: one switch check, applied through `load_badge()` to every operation that loads a badge, and also when creating a badge. Badges disabled (`enablebadges` off) gives `badgesdisabled`. A course badge while `badges_allowcoursebadges` is off gives `coursebadgesdisabled`.
- **#14**: when `get_badge_tags()` does not exist (Moodle 4.2), tags are read with `core_tag_tag::get_item_tags_array('core_badges', 'badge', $id)` instead.
- **#15**: when a non-Moodle exception escapes `execute_api`, the client sees the message "Internal error" unless `debugging('', DEBUG_DEVELOPER)` is on.
- **#3**: if any wrapper throws, `wrapper\manager::execute()` calls `abort_all_db_transactions()` and then rethrows.
- **Wrapper hardening**:
  - **Related badges:** for a course badge, they must be site badges or badges of the same course, must exist, and cannot be the badge itself (as `related_form.php` `get_badges_option()` does).
  - **Alignments:** `save_alignment` rejects an `alignmentid` that belongs to another badge.
  - **`add_module` runs the module's own form**, as `course/modedit.php` does:
    - `prepare_new_moduleinfo_data()` and the `mod_<name>_mod_form` are built.
    - The form is submitted through `updateSubmission()` (so each field gets the form's own type cleaning), with its defaults overlaid by the requested settings, and empty inputs are filled the way a browser would send them.
    - The cleaned values go through `validation()`, then `data_postprocessing()`, then `create_module()`.
    - Effects: form defaults fill in every field the module expects (`assign`, `quiz` and others create cleanly with minimal options); user values are cleaned by the form's types; invalid settings are rejected with the form's own error messages.
    - Tested for page, url, label, resource, assign, forum, quiz and lti.
    - For LTI, `typeid` must be a tool type available to the user in the course: `types_helper::get_lti_types_by_course()` on 4.3+, where manual instances are not allowed, and `lti_get_lti_types_by_course()` on 4.2, where manual instances need `mod/lti:addmanualinstance`.
  - **Gradebook:** `scaleid` must be a site scale or one of the course's scales.
  - **Question categories:** moving a category under itself or one of its descendants is rejected.
  - **`update_question` keeps embedded files:** each editor field's stored files are copied into a fresh draft area, as the question edit form does. That covers questiontext, generalfeedback, hints, answer feedback, true/false feedback and essay graderinfo. Saving the new version carries them over.
- **Clearer invalid-input errors**: wrapper input errors now use `moodle_exception('wrapper:invalidinput')`, whose message ("Invalid input: ...") reaches the client. Previously they were `invalid_parameter_exception`, which hides the details in debuginfo. I appended the lang string `wrapper:invalidinput`.
- **Regression tests**:
  - `test_badge_fields_are_cleaned_like_core_forms`: `<script>` and markup are stripped from description, name, caption and alignment fields; `javascript:` URLs and invalid emails are rejected; tags are kept on 4.2.
  - `test_badge_switches_and_relation_scoping`: a badge from another course is refused as related, a site badge is accepted, another badge's alignment cannot be overwritten, the course-badge switch and the site badge switch are both enforced.
  - `test_gradebook_rejects_foreign_scale`.
  - `test_question_category_cycle_is_rejected`.
  - `test_update_question_preserves_embedded_files`.
  - `test_failed_wrapper_aborts_open_transactions`.
  - `test_add_module_core_modules_use_form_defaults` (8 modules).
  - `test_add_module_runs_form_validation`: a resource with no file and an assignment due before it opens are both rejected.
  - `test_add_module_rejects_unavailable_lti_type`.

### Round 5 test results

- Moodle 4.2: `OK (285 tests, 1783 assertions)`
- Moodle 4.5: `OK (285 tests, 1787 assertions)`

## Round 6: PostgreSQL follow-up

- **`dmlreadexception` in the `add_module` tests:** the team lead's log was produced by an earlier version of `apply_module_form`.
  - In that version, `instance` stayed `''`, and `moodleform_mod::validation()` passed it into a `grade_items` bigint comparison. PostgreSQL rejects that; MariaDB silently accepted it.
  - The current version submits through `updateSubmission()`, which cleans every element with its `setType()`, so `instance` and `coursemodule` reach `validation()` as PARAM_INT 0.
- **Transaction abort:** `wrapper\manager::execute()` now calls `abort_all_db_transactions()` only if the failed wrapper left a transaction it opened itself still open. The check compares the transaction stack depth before and after the call. An outer transaction is not touched; that includes the per-test transaction Moodle's PHPUnit opens on PostgreSQL, and in a web service request there is none. `test_failed_wrapper_aborts_open_transactions` now uses a gradebook service that opens a transaction and throws.

### Round 6 test results

- Moodle 4.2 / MariaDB: `OK (285 tests, 1785 assertions)`
- Moodle 4.5 / PostgreSQL 16: `OK (285 tests, 1788 assertions)`
