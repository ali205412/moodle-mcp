# Phase 4: Permission-Gated Discovery & Safety - Research

**Researched:** 2026-04-21  
**Confidence:** HIGH

## Source-Backed Findings

### 1. Authoritative call-time checks live in external function code

- `external_api::validate_context()` enforces restricted context ancestry and then calls `require_login()` with redirect prevention.  
  Verified: `tmp/moodle/lib/external/classes/external_api.php`
- This means call-time permission and login checks remain the final authority and must not be replaced by discovery heuristics.

### 2. Explicit required capabilities are available up front

- Registered external functions store `capabilities` in `external_functions`, and the harvested catalog already normalizes those values.  
  Verified: `tmp/moodle/lib/db/install.xml`, current `classes/local/catalog/catalog_builder.php`
- `has_capability()`, `is_enrolled()`, `is_viewing()`, and `can_access_course()` are the reusable Moodle primitives for explicit eligibility checks.  
  Verified: `tmp/moodle/lib/accesslib.php`

### 3. Many modules expose explicit “access information” externals

- Moodle ships multiple access-information endpoints such as:
  - `mod_forum_get_forum_access_information`
  - `mod_quiz_get_quiz_access_information`
  - `mod_scorm_get_scorm_access_information`
  - `mod_lesson_get_lesson_access_information`
  - `mod_workshop_get_workshop_access_information`
  - `mod_data_get_data_access_information`
  - `mod_feedback_get_feedback_access_information`
  - `core_calendar_get_calendar_access_information`
  - `core_blog_get_access_information`
  Verified: `tmp/moodle/.../db/services.php` and corresponding externals
- These endpoints are strong signals for module-specific discovery enrichment because they already summarize activity/context eligibility in a machine-readable way.

### 4. Group/visibility/ownership checks are module-specific

- Example forum and assign externals show:
  - explicit `validate_context()`
  - `groups_get_activity_group()`
  - `groups_group_visible()`
  - module-specific methods like `forum_user_can_post_discussion()` or `require_view_grades()`  
  Verified: `tmp/moodle/mod/forum/externallib.php`, `tmp/moodle/mod/assign/externallib.php`
- Therefore Phase 4 should separate:
  - explicit, cross-cutting discovery filters
  - module-specific access-information enrichment
  - authoritative call-time denial handling

## Recommended Approach

- Filter discovery immediately on:
  - service scope
  - explicit required capabilities in the restricted context
  - loginrequired / connector mode / site policy flags
- Enrich discovery with:
  - risk level
  - confirmation requirement
  - call-time check hints
  - access-information companion hints when a matching access-information external exists
- Keep module/group/enrolment/visibility decisions as:
  - resolved when a safe, explicit companion access-information tool exists
  - otherwise marked as call-time checks rather than guessed

## Risks

- Over-filtering discovery based on guessed context rules would hide valid tools users can legitimately use.
- Under-explaining denials would keep the connector opaque even when call-time checks are correct.
- Some access-information externals require target ids, so discovery can only advertise their presence, not precompute every result.

## Primary Sources

- `tmp/moodle/lib/external/classes/external_api.php`
- `tmp/moodle/lib/accesslib.php`
- `tmp/moodle/lib/moodlelib.php`
- `tmp/moodle/mod/forum/externallib.php`
- `tmp/moodle/mod/assign/externallib.php`
- `tmp/moodle/mod/quiz/classes/external.php`
- `tmp/moodle/mod/workshop/classes/external.php`
- `tmp/moodle/calendar/externallib.php`
