# Auth / OAuth hardening and bulk MCP access: final report

Scope: auth/OAuth layer of `webservice_mcp` (Moodle 4.2+), security findings H1-H4, M1-M7, C1 and items 1-13,
plus feature requests (A) bulk admin-minted keys, (B) OAuth pre-approval, (C) Enterprise Managed Auth (jwt-bearer),
and follow-ups (assertion replay protection, users-file upload, idnumber selector, bulk user action).

- Plugin version: **`$plugin->version = 2026101008`**, release `0.9.0`. Auth schema and data changes are in upgrade
  steps 2026100900, 2026101000, 2026101003, 2026101004, 2026101005, 2026101006, 2026101007 and 2026101008; 2026101001 (task table) and 2026101002 (visibility cache) belong to other owners.
  Auth work no longer touches `db/`.
- Nothing is committed.

## Test results

Final runs on the real working tree, after the team lead's `webservice_mcp_task.familyid` fix:

| Moodle / DB | Result |
|---|---|
| 4.2 / MariaDB 10.11 (`scripts/run-local-tests.sh mariadb`) | `OK (247 tests, 1607 assertions)` |
| 4.5 / PostgreSQL 18rc1 (isolated compose project `mcpauth`) | `Tests: 246, Assertions: 1602, Errors: 1, Failures: 1` |

The 4.5 failures are both in the team lead's `mcp_tasks_test`, not auth code:

- `test_legacy_task_lifecycle`: "The credential that created this task has been revoked."
- `test_modern_task_via_cron`: status `failed` instead of `completed`.

They pass on 4.2/MariaDB. The tests create tasks with no credential family. A guess, not yet checked: a test runs
with no family, so `familyid` should be `''`, but on PostgreSQL the row probably reads back with a non-empty value,
so `tasks.php::assert_still_authorised()` calls `family_active()`, which returns false. Check what
`$record->familyid` holds on PostgreSQL. Testing with `empty($record->familyid)` instead of `!== ''` would also be
more robust. Every auth, OAuth, admin-key and jwt-bearer test passes on both versions. phplint, validate and
savepoints pass.

### jwt-bearer test on Moodle 4.2

The failure "The assertion type is not supported" was seen on Moodle 4.2 only. It came from the test, not the
grant:

- Moodle 4.2 bundles php-jwt 6.4.0, whose `JWT::encode()` does `array_merge($head, $header)`, so a caller-supplied
  `typ` is overwritten with `"JWT"`.
- At that time the grant accepted only `oauth-id-jag+jwt`.
- Fix (already in the tree): the grant accepts `typ` `JWT` or `oauth-id-jag+jwt` and refuses anything else (e.g.
  `at+jwt`). The test signs assertions by hand with `openssl_sign()` so the header is exactly what each case needs.
- Verification uses only APIs present in 6.4: `JWT::decode($jwt, $keys)` with two arguments,
  `JWK::parseKeySet($jwks, $defaultalg)`, and `JWT::$leeway`.

The run that reported this predates the fix; both final runs above include it.

## Contracts for the transport owner

### `transport_identity::resolve(string $token): ?stdClass`

Pass the plaintext bearer token; hashing happens internally.

| Property | Value |
|---|---|
| `user` | Moodle user record |
| `restrictedcontext` | `context` |
| `restrictedservice` | Connector service shortname |
| `tokentype` | 0 bootstrap, 1 OAuth access, 3 admin key. Refresh tokens (2) never resolve. |
| `sid` | Linked browser session, if any |
| `scope` | `mcp:read` or `mcp:read mcp:write`; set for OAuth tokens and admin keys |
| `resourceuri` | Canonical `server.php` URL for OAuth tokens and admin keys |
| `oauthclientid` | OAuth client id, or null |
| `credential` | The credential row; `credential->id` is the credential id |
| `credentialid` | int, same id |
| `familyid` | `f_<oauth family id>` for OAuth tokens, `c_<credential id>` for everything else (admin keys included) |

`resolve()` returns null when:

- the token is unknown, revoked or expired
- its browser session has ended
- the credential has an IP restriction and the client IP doesn't match
- it is a refresh token
- it is an OAuth token whose client is revoked or missing
- it is an OAuth token and `oauthenabled=0`

### Revocation check for file tickets

`(new credential_manager())->family_active(string $familykey): bool` stays true while at least one credential in
the family is unrevoked and unexpired. Bind tickets to `familyid` and check this on download.

### 401 challenge scope

`"mcp:read mcp:write"`, which is what `oauth_service::default_scope_string()` already returns. It deliberately
leaves out `offline_access` (MCP 2026-07-28: resources SHOULD NOT advertise it).

### Connector service flags

- A newly created connector service has `downloadfiles=1` and `uploadfiles=1`.
- The 2026101000 upgrade sets both to 1 once on the existing service, in the same update that clears its
  `component`. Nothing forces them again afterwards, so admins may turn them off.

### Code moved

Moved to keep files under 600 lines. The methods the transport calls are unchanged: `build_bearer_challenge`,
`canonical_resource_uri`, `default_scope_string`, `is_enabled`, `scope_contains`, `SCOPE_*`.

| Before | Now |
|---|---|
| Authorize logic on `service` | `$oauth->authorization()` → `classes/local/oauth/authorization.php` |
| Token grants on `service` | `classes/local/oauth/token_issuer.php` |
| Discovery documents on `service` | `(new metadata($oauth))->build_protected_resource_metadata()`, `build_authorization_server_metadata()`, `build_openid_configuration()` |
| Connection listing on `credential_manager` | `classes/local/auth/connection_service.php` |

### Tests that create their own connector service

A test that inserts its own `webservice_mcp_connector` service must call
`set_config('connectorserviceid', $id, 'webservice_mcp')` first. Otherwise it gets `connectorservicenotowned`.

## Key finding: core was deleting the connector service on every upgrade

The plugin has no `db/services.php`. On every plugin upgrade, core runs
`lib/upgradelib.php::external_update_descriptions()` → `\core_external\util::delete_service_descriptions()`. That
deletes every service with `component=webservice_mcp`, together with its allowed users, function list and tokens.
Every admin restriction on the connector service was therefore wiped on each upgrade.

Fix:

- The service is now created with `component = NULL`.
- Ownership is tracked by the plugin setting `connectorserviceid`.
- The 2026101000 upgrade step clears the component on the existing service before core's cleanup runs. It also sets
  the file flags once and runs the function sync.

## Security fixes

### H1: connector service manager (`classes/local/auth/connector_service_manager.php`)

- **Create-only:** an existing service is never updated, so disabling it, required capability, restricted users,
  user removals, IP restrictions and expiry all stick.
- **Ownership:** a same-named service the plugin didn't create is refused (`connectorservicenotowned`, M5).
- **Issue-time check:** `require_user_authorised()` mirrors core's
  `\core_external\util::generate_token_for_current_user()`: service enabled, required capability, allowed-users
  list, `validuntil`, `iprestriction`.
- **Provisioning markers:** a user is added to `external_services_users` only if the service is plugin-owned and the
  user was never provisioned before. Markers live in the new table `webservice_mcp_provision`, so an admin's removal
  sticks.
- **Function sync:** `sync_service()` adds newly installed external functions and records each one it adds. A
  function that was recorded but is missing now was removed by an admin and is never re-added.
- **When sync runs:** from the upgrade step, from the hourly task `\webservice_mcp\task\sync_connector_service`, and
  once when the service is first created. It no longer runs on every token request. The old string/int `!==` dirty
  check is gone with the update path.
- **For admin issuance (A):** `admin_authorisation_problem()` checks without side effects, and
  `authorise_user_explicitly()` adds the user.

### H2: phishing protections (`oauth/authorize.php`, `classes/local/oauth/client_registry.php`)

- **Consent page:** login is required before anything is validated. The page shows:
  - the redirect host, prominently ("you will be sent to …")
  - a warning when the client registered itself (DCR, unverified)
  - the website of Client ID Metadata Document clients
  - the context name, the scopes in plain language, and the signed-in account
- **No invented names:** the "Claude" default client name is removed; a missing name falls back to the redirect
  host.
- **Framing:** the page sends `X-Frame-Options: DENY` (after `$OUTPUT->header()`, overriding Moodle's `sameorigin`)
  and `Content-Security-Policy: frame-ancestors 'none'`.
- **Redirect allowlist:** setting `allowedredirecthosts` (default claude.ai, claude.com, localhost, 127.0.0.1,
  [::1]; `*` allows any) is enforced at registration and at authorize.
- **Registration limits:** at most 10 redirect URIs of up to 1024 characters each, and 20 registrations per IP per
  hour (MUC `oauth_ratelimit`). Names are stripped of control characters and capped at 100 characters.
- **PHP 8.0:** the PHP 8.1 `never` return type is gone.

### H3: revocation

- **Account changes** (`db/events.php` → `\webservice_mcp\observer`):
  - `user_password_updated` revokes all of the user's credentials and burns pending codes.
  - `user_updated`: if the user is now deleted, suspended, unconfirmed, nologin, or on a disabled auth plugin, the
    same happens.
  - `user_deleted` deletes the user's credentials, codes and pre-approvals.
- **Token families:**
  - `familyid` and `familycreated` are stored on each credential.
  - Absolute family lifetime is set by `oauthfamilylifetime` (default 90 days).
  - On refresh, `core_user::require_active_user` (suspended and nologin included), `webservice/mcp:use` in the
    context, the site-admin policy and the service authorisation are all re-checked. If any fails, the family is
    revoked.
- **Replay:** reusing a code revokes the family it produced (M2). Presenting an already-rotated refresh token revokes
  the whole family (M1).
- **Concurrency:** codes and refresh tokens are redeemed under `\core\lock\lock_config::get_lock_factory('webservice_mcp')`.
  The new pair is issued before the old one is revoked, inside a delegated transaction. A failed exchange (wrong
  verifier, client, redirect or resource, or expired code) burns the code.

### H4: login-as and site admins

- Authorize, launch and bootstrap refuse `\core\session\manager::is_loggedinas()` (`loginasnotallowed`).
- Setting `allowsiteadmins` (default 1) blocks site admins when set to 0.

### Hashed storage (M4)

- Credential tokens and authorization codes are stored as SHA-256 and looked up by hash. The upgrade hashes existing
  rows in place.
- Plaintext is returned only once, at issue time (`->token` on the issued record).

### Loopback redirects and offline_access (M6, M7)

- **M6:** loopback `http` redirect URIs (localhost, 127.0.0.1, [::1]) match regardless of port (RFC 8252 §7.3).
- **M7:**
  - `offline_access` is part of the default DCR scope and of the authorization server's `scopes_supported`.
  - It is accepted at authorize even when not registered, and granted automatically to clients registered for
    `refresh_token`.
  - Protected-resource metadata lists only `mcp:read` and `mcp:write`.

### Client ID Metadata Documents (item 7)

- **Fetching:** HTTPS URL client ids with a path are fetched with Moodle's `\curl`, which applies
  `\core\files\curl_security_helper`.
  - Fetch limits: 5s timeout, no redirects, 64KB cap.
  - Results are cached in MUC (`oauth_client_metadata`) for 1 hour.
- **Validation:**
  - `client_id` must equal the URL exactly.
  - `token_endpoint_auth_method` must be `none`.
  - redirect URIs, grant types and response types are checked.
- **Storage:** the client is stored in the client table (`isdynamic=0`) so admins can revoke it; a revoked client is
  never fetched again.
- **Metadata advertises:**
  - `client_id_metadata_document_supported: true`
  - `authorization_response_iss_parameter_supported: true`
  - `iss` is added to every authorization response (RFC 9207)
  - `code_challenge_methods_supported: ["S256"]`
  - `revocation_endpoint`, `response_modes_supported`
- **DCR** accepts `application_type` (`native` or `web`).
- **Discovery:**
  - `/webservice/mcp/.well-known/openid-configuration` is now a parseable OpenID document: `jwks_uri` points at the
    empty `oauth/jwks.php`, plus `subject_types_supported` and `id_token_signing_alg_values_supported`. It is the
    only metadata URL MCP clients can reach without web-server rewrites.
  - The RFC 8414 document remains at the plugin path.
  - The README documents nginx rewrites for `/.well-known/oauth-authorization-server/webservice/mcp`,
    `/.well-known/openid-configuration/webservice/mcp` and
    `/.well-known/oauth-protected-resource/webservice/mcp/server.php`.
  - The protected-resource `resource` is the canonical `server.php` URL.

### Revocation endpoint and connected apps (item 8)

- **`oauth/revoke.php`** (RFC 7009):
  - Client authentication works as at the token endpoint.
  - Revoking a refresh token revokes its family; revoking an access token revokes only that token.
  - Unknown tokens, or tokens of another client, return `200 {}`.
- **`/webservice/mcp/connections.php`:**
  - Logged-in users see their own OAuth connections, admin keys and bootstrap credentials: application, issued by,
    connected, last used, expires.
  - Each can be revoked with a sesskey POST.
  - Holders of `webservice/mcp:manageconnectors` can open `?userid=N` or `?all=1`; settings link to it.

### Token endpoint and transport (item 9)

- `token.php`, `register.php` and `revoke.php` catch every `Throwable` and return OAuth JSON (`server_error`), never
  an HTML error page.
- PKCE: the challenge and verifier must match `^[A-Za-z0-9\-._~]{43,128}$`. The method must be `S256`; a missing
  method (meaning "plain") is refused.
- HTTP Basic client credentials are URL-decoded (RFC 6749 §2.3.1).
- `resolve_credential()` enforces the credential's `iprestriction`.
- `transport_identity::resolve()` rejects OAuth tokens when OAuth is disabled or their client is revoked.

### `launch.php` (item 10)

- A GET shows a confirm page; a credential is issued only on POST with sesskey.
- It sends `Cache-Control: no-store` and refuses login-as.

### Upgrade crash C1 (item 11)

- The `userid_idx` index collided with the `userid_fk` key ("Key userid_fk collides with index") on the credential
  and audit tables. It is removed from both the upgrade create blocks and `install.xml`.
- The `install.xml` VERSION attribute is updated.

### Privacy (item 12)

`\webservice_mcp\privacy\provider` implements the metadata, `core_userlist_provider` and `plugin\provider`
interfaces at system context for:

- `webservice_mcp_credential`
- `webservice_mcp_oauth_code`
- `webservice_mcp_audit`
- `webservice_mcp_memory`
- `webservice_mcp_preapproval`

Export covers credentials (never tokens), audit, memory and pre-approvals. Deleting a user removes their rows. When
an issuer is deleted, what they granted stays but their `issuerid` is cleared. `webservice_mcp_provision` is
access-control state, like core's `external_services_users`, and is kept so a privacy delete can't undo an admin's
removal.

### Cleanup (item 13)

The daily task `\webservice_mcp\task\cleanup` deletes:

- credentials revoked or expired more than 30 days ago
- authorization codes more than a day past expiry
- expired pre-approvals
- audit rows older than `auditretentiondays` (default 90; 0 keeps them)
- DCR clients never used within 30 days

`webservice/mcp:manageconnectors` now has `riskbitmask` RISK_CONFIG | RISK_PERSONAL.

## (A) Bulk admin-minted per-user keys

**Capability:** `webservice/mcp:issueforothers` (system context, RISK_CONFIG | RISK_PERSONAL | RISK_DATALOSS, given
to no role by default; site admins have it implicitly).

### Key properties

- Tokentype 3 (`credential_manager::TOKEN_TYPE_ADMIN`): durable, not OAuth, no refresh.
- Stored hashed; records `issuerid`. The label is stored in `name`.
- Scope `mcp:read` or `mcp:read mcp:write`, enforced by the transport because the scope is non-empty.
- `resourceuri` is the canonical server URL, and keys are bound to the connector service like any other credential.
- Expiry defaults to 365 days, capped by `adminkeymaxdays`. A context restriction is optional (default: system).
- Resolves through `transport_identity` like any other credential; family id `c_<id>`.

### Eligibility (per user, with reason codes)

| Reason code | Meaning |
|---|---|
| `notfound` | User doesn't exist or is deleted |
| `guest` | Guest user |
| `inactive` | Suspended, unconfirmed, or auth `nologin` |
| `siteadmin` | Target is a site admin but the issuer isn't |
| `siteadminsdisallowed` | Target is a site admin and `allowsiteadmins=0` |
| `nocapability` | No `webservice/mcp:use` in the chosen context |
| `servicenotavailable` | Connector service is disabled |
| `missingrequiredcapability` | User lacks the service's required capability |
| `invalidtimedtoken` | User's access to the service has expired |
| `usernotallowed` | User was removed from the restricted service and the issuer can't re-add them |

Only site admins and holders of `moodle/webservice:managealltokens` can re-add a user an admin removed. With
"fail on skip", nothing is issued if any user would be skipped.

### Issuance

Issuance runs under a single lock (`adminkeys`) with users loaded in chunks of 500, and returns one row per user.
Each issue and revoke fires an event.

### Admin page: `/webservice/mcp/admin/keys.php`

Reached via Site administration > Plugins > Web services > Model Context Protocol > "MCP access for users", or by
direct URL for holders of the capability.

- **Actions:** issue keys, pre-approve OAuth, remove pre-approval.
- **User selection** (any combination):
  - pasted usernames, emails or ids
  - pasted ID numbers
  - a users file (CSV or text, explained below)
  - users carried over from Bulk user actions
  - a cohort
  - a course, optionally limited to one role
  - all users with `webservice/mcp:use` at system level
- **Users file:** if the header row names a `userid`, `id`, `username`, `email` or `idnumber` column, that column is
  used; otherwise the first column is read. Commas, semicolons and tabs are accepted, and a BOM is stripped.
- **Options:** label, access level (read or read/write), days valid, context id, redirect hosts (pre-approval only),
  and "do nothing if any selected user can't be included".
- **After issuing:** a results table with status and reason per user, plus a "Download keys (CSV)" link embedded in
  that same POST response as a `data:` URI. Plaintext tokens are never persisted, and the page sends
  `Cache-Control: no-store`.
- **CSV columns:** `userid, username, email, fullname, label, scope, expires (ISO 8601), token, server_url,
  claude_code_command, mcp_json`.
  - `claude_code_command`: `claude mcp add --transport http moodle <server_url> --header "Authorization: Bearer <token>"`
  - `mcp_json`: `{"type":"http","url":"…","headers":{"Authorization":"Bearer …"}}`
- **Existing keys:** active admin keys with filters (label, issuer user id, user id) and "Revoke selected" or
  "Revoke all matching the filter". Pre-approvals are listed below that.

### Bulk user actions

"Generate MCP keys or pre-approve MCP access" appears in Site administration > Users > Bulk user actions for
holders of `issueforothers`.

- Moodle 4.2/4.3: the legacy callback `webservice_mcp_bulk_user_actions()` in `lib.php`.
- Moodle 4.4+: the `\core_user\hook\extend_bulk_user_actions` hook registered in `db/hooks.php`, which suppresses the
  legacy callback there.
- The selected users (`$SESSION->bulk_users`) show up on the admin page as a pre-ticked "Include the N user(s)
  selected in Bulk user actions" option.
- There is no per-user action on the profile page; selecting one user in Bulk user actions covers that case.

### CLI: `php webservice/mcp/cli/keys.php`

```text
Exactly one action:
  --issue          Issue per-user admin keys (requires --output=path.csv; file created with mode 0600)
  --list           List active admin-issued keys (filters: --label, --issuer, --userids)
  --revoke         Revoke admin-issued keys (filters: --label, --issuer, --userids)
  --preapprove     Pre-approve OAuth consent (--redirect-hosts, --scope, --expires-days)
  --unpreapprove   Remove pre-approvals (selectors, or --issuer=ID for all by that issuer)
Selectors: --userids=1,2 --usernames=a,b --emails=x@y,z@w --idnumbers=E1,E2 --file=users.csv
           --cohort=ID --course=ID [--role=ID] --all-mcp-users
Options:   --label=TEXT --expires-days=N --scope=read|write --contextid=ID --fail-on-skip
           --as-user=USERNAME (default: main admin, get_admin())
```

Examples:

```bash
php webservice/mcp/cli/keys.php --issue --cohort=12 --label="Spring pilot" --scope=write \
    --expires-days=90 --output=/root/mcp-keys.csv
php webservice/mcp/cli/keys.php --issue --file=/root/staff.csv --label=staff --output=/root/staff-keys.csv
php webservice/mcp/cli/keys.php --issue --course=42 --role=3 --label="Teachers C42" --output=/tmp/t.csv
php webservice/mcp/cli/keys.php --list --label="Spring pilot"
php webservice/mcp/cli/keys.php --revoke --label="Spring pilot"
php webservice/mcp/cli/keys.php --revoke --userids=17,18
php webservice/mcp/cli/keys.php --preapprove --all-mcp-users --scope=write
php webservice/mcp/cli/keys.php --preapprove --idnumbers=E100,E101 --redirect-hosts=claude.ai --expires-days=180
php webservice/mcp/cli/keys.php --unpreapprove --usernames=alice,bob
```

### Events

`\webservice_mcp\event\credential_issued` (crud `c`) and `\webservice_mcp\event\credential_revoked` (crud `u`) fire
for every credential issued or revoked: OAuth access and refresh, bootstrap, and admin keys.

| Field | Value |
|---|---|
| `objecttable` / `objectid` | `webservice_mcp_credential` / the credential id |
| `relateduserid` | the target user |
| `userid` | the issuer or revoker |
| `other` | `label`, `tokentype`, `oauthclientid` |

Bulk revocations (family, all of a user's credentials) fire one event per credential.

### Notification

Setting `notifyonadminkey` (default 0) sends the user a Moodle notification through the message provider
`adminkeyissued` (`db/messages.php`; popup and email enabled by default). It links to the Connected apps page.

### Connected apps

Users see admin keys (label, issued by, expiry, last used) and can revoke them. "Last used" is written at most once
per 5 minutes.

## (B) OAuth pre-approval

For claude.ai and Claude Desktop, which can't hold per-user static keys.

- Same page and CLI, same selectors and eligibility checks.
- Stored in `webservice_mcp_preapproval`. Re-approving replaces the existing row for that user and context.
- Defaults: hosts claude.ai, claude.com, localhost, 127.0.0.1, [::1]; scope read or read/write; no expiry unless
  `expiresdays` is set.
- **Auto-approval in `oauth/authorize.php`:** after login and full request validation, a valid pre-approval skips
  the consent screen when:
  - it covers the redirect host (loopback hosts compared without brackets),
  - the context matches the requested one,
  - and the requested scope, ignoring `offline_access`, is within the pre-approved scope.
- The code is then issued immediately. With auth_oidc SSO the whole flow is silent.
- Each auto-approval is written to `webservice_mcp_audit` (action `oauth_preapproved`, client id in `toolname`,
  detail `preapproval`). `credential_issued` fires at token time as usual.
- A redirect host outside the pre-approval is never auto-approved, whether the client is DCR or not.
- Limitation: because there's no consent click, sending a pre-approved, logged-in user to an authorize URL returns a
  code to the client's pre-approved redirect host. Keep `redirecthosts` tight.

## (C) Enterprise Managed Authorization (RFC 7523 jwt-bearer, Identity Assertion JWT Authorization Grant)

`oauth/token.php` accepts `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer` with `assertion=…` only when
`emaenabled=1`; the grant is advertised in `grant_types_supported` only then. Implementation:
`classes/local/oauth/jwt_bearer_grant.php` (verification) and `token_issuer::issue_for_assertion()` (issuance).

### Client

- The client authenticates as at the token endpoint.
- It must be a Client ID Metadata Document client (fetched on demand if not yet stored) or pre-registered
  (`isdynamic=0`). DCR clients get `unauthorized_client`.

### Assertion checks, in order

1. **Format and type:** the assertion must be a compact JWT whose header `typ`, if present, is `oauth-id-jag+jwt` or
   `JWT`. Anything else, such as `at+jwt`, is a different token type and is refused.
2. **Algorithm:** RS256/384/512 or ES256/384.
3. **Issuer:** must be on `ematrustedissuers` (one URL per line, trailing slash ignored).
4. **Keys:**
   - Discovered from `{iss}/.well-known/openid-configuration`, whose `jwks_uri` must be HTTPS.
   - Both documents are fetched with `\curl` and `curl_security_helper`: 5s timeout, no redirects, 256KB cap.
   - The JWKS is cached in MUC `ema_jwks` for 1 hour. Only RSA and EC keys are used, via
     `Firebase\JWT\JWK::parseKeySet` (php-jwt 6.4 in the 4.2 tree, available on 4.2+).
5. **Signature and time:** `Firebase\JWT\JWT::decode`, with `JWT::$leeway = 60` for exp, nbf and iat.
6. **Claims:**
   - `iss` matches.
   - `exp` is present and no more than 1 day ahead.
   - `aud` contains this server's issuer URL or token endpoint.
   - `client_id` equals the authenticated client.
7. **Replay:**
   - `jti` is required for `oauth-id-jag+jwt`, and for plain `JWT` too unless `emarequirejti=0`.
   - The pair (issuer, `jti`) is accepted once until the assertion expires, under a lock and recorded in the table
     `webservice_mcp_jti` (round 3; it was a MUC cache before). Assertions valid for more than 1 day are refused.
   - A reused assertion gets `invalid_grant`.
8. **User mapping** (`emausermatchfield`; exactly one user must match):

   | Setting | Claims tried |
   |---|---|
   | `email` (default) | `email`, `preferred_username`, `upn` (values containing `@`) |
   | `username` | `preferred_username`, `upn`, `email` (lowercased) |
   | `idnumber` | `sub`, `oid` |

   If nothing matches, it falls back to auth_oidc's `auth_oidc_token` table (`oidcuniqid` = `oid` or `sub`, giving
   `username`).
9. **Eligibility:** the user must be active, hold `webservice/mcp:use`, pass the site-admin policy and be authorised
   on the service.

### Issuance

An access token plus a refresh token if the client has the `refresh_token` grant, in a new family. Scope is the
requested scope within the client's registered scope; context is system. Every failure returns `invalid_grant`.

## Schema changes (all in upgrade step 2026101000 and `install.xml`)

- **`webservice_mcp_credential`:**
  - `token` now holds a SHA-256 hash; existing rows are hashed in place.
  - New fields `familyid` char(64) null, `familycreated` int(10) null, `issuerid` int(10) null.
  - Index `familyid_idx`; key `issuerid_fk` → user.id.
  - Removed index `userid_idx` (C1).
  - Tokentype 3 means admin-issued.
- **`webservice_mcp_oauth_code`:** `code` now holds a SHA-256 hash (hashed in place); new field `familyid` char(64)
  null.
- **`webservice_mcp_audit`:** removed index `userid_idx` (C1).
- **New `webservice_mcp_provision`:** `id, serviceid, itemtype (user|function), itemname, timecreated`.
  Key `serviceid_fk`, unique index `service_item_uix (serviceid, itemtype, itemname)`.
- **New `webservice_mcp_preapproval`:** `id, userid, scope, redirecthosts (text), contextid, issuerid, expiry
  (0 = none), timecreated`. Keys `userid_fk`, `issuerid_fk`, `contextid_fk`.
- **Data migration:** the existing `component=webservice_mcp` service gets `component=NULL`, `downloadfiles=1` and
  `uploadfiles=1`, and its id is stored in config `connectorserviceid`. The function sync then runs once.
- **Other `db/` files:**
  - `access.php`: new capability `webservice/mcp:issueforothers`; `manageconnectors` gets a riskbitmask.
  - `caches.php`: new definitions `oauth_client_metadata`, `oauth_ratelimit`, `ema_jwks` (`ema_jti` was added then removed in round 3).
  - New files `events.php`, `tasks.php`, `messages.php`, `hooks.php`.

## Settings (`settings.php`)

| Setting | Default | Purpose |
|---|---|---|
| `allowedredirecthosts` | claude.ai, claude.com, localhost, 127.0.0.1, [::1] | OAuth redirect host allowlist (`*` = any HTTPS) |
| `allowsiteadmins` | 1 | Allow site admins to connect MCP clients |
| `oauthfamilylifetime` | 90 days | Absolute OAuth grant lifetime |
| `auditretentiondays` | 90 | Audit retention (0 = keep forever) |
| `adminkeymaxdays` | 365 | Maximum admin key lifetime |
| `notifyonadminkey` | 0 | Notify users when an admin creates a key for them |
| `emaenabled` | 0 | Accept jwt-bearer identity assertions |
| `ematrustedissuers` | empty | Trusted assertion issuers, one per line |
| `emarequirejti` | 1 | Require `jti` on plain JWT assertions (always required for ID-JAG) |
| `emausermatchfield` | email | email, username or idnumber |
| link | n/a | "View and revoke connected MCP apps for all users" (`connections.php?all=1`) |
| admin page | n/a | `webservice_mcp_keys` → `/webservice/mcp/admin/keys.php` |

Internal config: `connectorserviceid`.

## New and changed files

**New classes**

- `classes/local/oauth/`: `client_registry.php`, `authorization.php`, `token_issuer.php`, `metadata.php`,
  `jwt_bearer_grant.php`
- `classes/local/auth/`: `admin_key_service.php`, `preapproval_service.php`, `connection_service.php`
- `classes/event/`: `credential_issued.php`, `credential_revoked.php`
- `classes/form/admin_keys_form.php`
- `classes/task/`: `cleanup.php`, `sync_connector_service.php`
- `classes/observer.php`, `classes/hook_callbacks.php`

**New pages and endpoints**

- `oauth/revoke.php`, `oauth/jwks.php`
- `connections.php`, `admin/keys.php`, `cli/keys.php`

**New `db/` files:** `events.php`, `tasks.php`, `messages.php`, `hooks.php`

**Changed**

- `classes/local/auth/`: `credential_manager.php`, `connector_service_manager.php`, `bootstrap_service.php`,
  `transport_identity.php`
- `classes/local/oauth/service.php`, `classes/privacy/provider.php`
- `oauth/`: `authorize.php`, `token.php`, `register.php`
- Discovery documents: `.well-known/*/index.php`, `oauth/.well-known/*/index.php`, `oauth/protected-resource/index.php`
- `launch.php`, `settings.php`, `lib.php` (appended `webservice_mcp_bulk_user_actions()`), `version.php`
- `db/`: `install.xml`, `upgrade.php`, `access.php`, `caches.php`
- `lang/en/webservice_mcp.php` (appended only), `README.md`

**Tests**

- Rewritten or extended: `oauth_service_test`, `credential_manager_test`, `connector_service_manager_test`,
  `launch_test`, `auth_admin_test`, `connector_flow_test` (sets `connectorserviceid`).
- New: `privacy_provider_test`, `admin_key_service_test`, `jwt_bearer_grant_test`.

Negative tests:

- **OAuth codes and tokens:** code reuse revokes the family; refresh replay revokes the family; wrong verifier burns
  the code; plain or malformed PKCE rejected; family lifetime enforced.
- **Redirects and registration:** disallowed redirect host refused at registration and authorize; 11 redirect URIs
  rejected; loopback on any port; registration rate limit.
- **Users and accounts:** login-as refused; site admins refused when disallowed; refresh re-checks capability.
- **Connector service:** disabled service and required capability preserved and enforced; admin-removed user and
  function not re-added; foreign service refused.
- **Revocation:** password change, suspension and deletion revoke; RFC 7009 revocation; transport rejects revoked
  clients and OAuth-off.
- **Other:** Basic credentials URL-decoded; IP restriction enforced; tokens stored hashed.
- **Client ID Metadata Documents:** flow with caching; validation including `client_id` mismatch, shared secret,
  missing or non-HTTPS redirects, implicit response type, too large, not an object, malformed client id; a revoked
  client is never fetched.
- **Cleanup task and privacy:** cleanup purges; privacy export and delete.
- **Admin keys and pre-approval:** issuing with skip reasons, CSV and events; issuer restrictions; fail on skip;
  restricted service; lifetime cap, list and revoke; last-used throttle; pre-approval matching; cohort and
  all-MCP-users selection; users-file parsing; idnumber selector; bulk action only for issuers.
- **jwt-bearer:** valid by email and by username; rejected for bad issuer, audience, client_id, expiry, unknown user,
  wrong type, wrong key, malformed input, grant disabled; DCR client refused; replay, missing `jti`, lifetime longer
  than 1 day; plain JWT without `jti` allowed when `emarequirejti=0`.

## Round 3: durable replay store, refresh grace window, client admin, configurable retention

### Final test results (real tree, plugin version 2026101003)

| Moodle / DB | Result |
|---|---|
| 4.2 / MariaDB 10.11 (`scripts/run-local-tests.sh mariadb`) | `OK (251 tests, 1638 assertions)` |
| 4.5 / PostgreSQL 18rc1 (isolated compose project `mcpauth`) | `OK (251 tests, 1641 assertions)` |

After these runs I only wrapped two long lines in `admin/clients.php` and let phpcbf reformat the files listed
below. phpcs then reports only long-line warnings; `php -l` is clean.

### Schema: upgrade step 2026101003 (`install.xml` VERSION 2026101003, `version.php` 2026101003)

- **New table `webservice_mcp_jti`:** `id`, `keyhash` char(64) (SHA-256 of issuer + jti), `expiresat` int.
  Unique index `keyhash_uix`, index `expiresat_idx`. It holds no user data, so it is not in the privacy provider.
- **`webservice_mcp_credential.rotatedat`** int null: when a refresh token was replaced.
- The `ema_jti` MUC cache definition and its lang string are removed.

### jti replay protection is now durable

- `jwt_bearer_grant::consume_jti()` works under a lock: an unexpired row means replay (`invalid_grant`).
- An expired row is reused; otherwise a row is inserted, and a unique-index violation from a concurrent insert also
  counts as replay.
- The cleanup task deletes rows that expired more than an hour ago.
- The test proves the store survives `cache_helper::purge_all()`.

### Refresh rotation grace window (setting `refreshgraceseconds`, default 30, 0 disables)

- On rotation the old refresh token is marked revoked with `rotatedat = now` (`credential_manager::mark_rotated()`).
- Presenting it again within the window gets a fresh access/refresh pair in the same family, without revoking
  anything, when all of these hold:
  - it is the same client (another client's attempt is rejected earlier with `invalid_grant` and changes nothing)
  - the token belongs to a family
  - that family still has an active credential, so a connection the user revoked is not revived
- Each reuse inside the window mints a new pair; the window is not extended.
- Any other reuse (after the window, a revoked family, or grace disabled) is treated as theft: the family is revoked
  and the request gets `invalid_grant`.
- Tests cover: concurrent reuse inside the window, another client's reuse, a revoked family, reuse after the window
  (family revoked), and grace disabled.

### OAuth client administration

- **Page:** `/webservice/mcp/admin/clients.php`, admin external page `webservice_mcp_clients` (Site administration >
  Plugins > Web services > Model Context Protocol > "MCP OAuth clients"). Requires
  `webservice/mcp:manageconnectors`.
  - Pre-register a client: name, redirect URIs (hosts must be on `allowedredirecthosts`), public or confidential,
    read or read/write (both include offline_access).
  - The client is stored with `isdynamic=0`, so the consent screen treats it as administrator-registered and it may
    use the jwt-bearer grant.
  - A confidential client's secret (`client_secret_basic`, stored as a password hash) is shown only once.
- **List:** paginated, filter by registration type (self-registered / metadata document / pre-registered), and
  show or hide revoked clients. Columns: name, client id, type, redirect hosts, active token count, created.
- **Revoke:** marks the client revoked, revokes all of its credentials (with events) and burns its pending codes.
  The transport already rejects tokens of revoked clients.
- **CLI:** `php webservice/mcp/cli/clients.php`

```text
--register --name=TEXT --redirect-uris=URI,URI [--confidential] [--scope=read|write]   (prints client_id/secret once)
--list [--type=dynamic|metadata|registered] [--include-revoked]
--revoke --clientid=ID
--as-user=USERNAME (default: main admin)
```

- **Code:** `classes/local/oauth/client_admin_service.php` (register, list, count, revoke, registration type),
  `classes/form/client_form.php`. `client_registry::register_dynamic_client()` is renamed `register_client()` with an
  `$isdynamic` flag; `service::register_dynamic_client($metadata, bool $isdynamic = true)`.

### Retention and list sizes are now settings; listings are paginated

| Setting | Default | Replaces |
|---|---|---|
| `credentialretentiondays` | 30 | cleanup constant |
| `dcrclientretentiondays` | 30 | cleanup constant |
| `adminlistperpage` | 50, max 1000 | hard caps of 1000/500 |

Paginated listings:

- **Connected apps (`connections.php`):** paginated in SQL by connection, grouping on `f_<family>` or `c_<id>`.
  New methods: `connection_service::count_connections()`, `list_connections($userid, $limitfrom, $limitnum)`,
  `connection_service::per_page()`.
- **Admin keys and pre-approvals** on `admin/keys.php` (`kpage` / `ppage` paging bars). New methods
  `admin_key_service::count_keys()` and `preapproval_service::count_preapprovals()`; both list methods take
  `$limitfrom` and `$limitnum`.
- **The OAuth client list** on the new clients page.

### New files this round

- `admin/clients.php`, `cli/clients.php`
- `classes/local/oauth/client_admin_service.php`, `classes/form/client_form.php`
- `tests/client_admin_service_test.php`: client registration, listing, revocation and capability; pagination by
  family; retention settings; jti purge.

Changed this round:

- `db/install.xml`, `db/upgrade.php`, `db/caches.php`, `version.php`
- `classes/local/oauth/jwt_bearer_grant.php`, `token_issuer.php`, `client_registry.php`, `service.php`
- `classes/local/auth/credential_manager.php`, `connection_service.php`, `admin_key_service.php`,
  `preapproval_service.php`
- `classes/task/cleanup.php`, `connections.php`, `admin/keys.php`, `settings.php`
- `lang/en/webservice_mcp.php` (appended)
- `tests/oauth_service_test.php`, `tests/jwt_bearer_grant_test.php`

## Round 4: client secret rotation and library-loading audit

### Final test results (real tree, plugin version 2026101004)

| Moodle / DB | Result |
|---|---|
| 4.2 / MariaDB 10.11 (`scripts/run-local-tests.sh mariadb`) | `OK (252 tests, 1648 assertions)` |
| 4.5 / PostgreSQL 18rc1 (isolated compose project `mcpauth`) | `OK (252 tests, 1651 assertions)` |

phpcs on this round's files shows only long-line warnings (one, in a test, was wrapped after the runs).

### Schema: upgrade step 2026101004 (`install.xml` VERSION 2026101004, `version.php` 2026101004)

`webservice_mcp_oauth_client` gets two new fields:

- `previoussecret` char(255) null: password hash of the secret replaced by the last rotation.
- `previoussecretexpires` int null: when that previous secret stops being accepted.

### In-place secret rotation

- **Code:** `client_admin_service::rotate_secret(string $clientid, stdClass $actor): string`.
  - Requires `webservice/mcp:manageconnectors`.
  - Only active confidential clients; public or revoked clients raise `invalid_client`.
  - Stores a new password hash and returns the new plaintext once.
- **Overlap:** setting `secretrotationoverlap` (seconds, default 0).
  - When it is above 0, the old hash moves to `previoussecret` until now + overlap.
  - Token and revocation endpoint client authentication (`service::authenticate_client`) accepts the current secret,
    or the previous one only until it expires.
  - With 0, the old secret stops working immediately.
  - A further rotation replaces the stored previous secret, so only the latest replaced secret can still work.
- **Admin page:** `admin/clients.php` shows a "Rotate secret" button (POST + sesskey) next to Revoke for active
  confidential clients. The new secret appears once with a copy-now warning.
- **CLI:** `php webservice/mcp/cli/clients.php --rotate-secret --clientid=ID` prints `{client_id, client_secret}`
  once.
- **Test** (`client_admin_service_test::test_rotate_secret`): immediate rotation rejects the old secret; with a
  1-hour overlap the previous secret works and older ones don't; an expired overlap rejects it; rotating a public
  client is refused.

### Library-loading audit (cold requests)

Every auth/OAuth/admin entry point and the classes it reaches were checked for functions that live outside the
libraries `lib/setup.php` preloads. Preloaded: weblib, outputlib, datalib, accesslib, enrollib, messagelib,
moodlelib.

| Dependency | Where | How it is loaded |
|---|---|---|
| `\webservice` | `connector_service_manager::webservice_manager()` | requires `webservice/lib.php` |
| `\curl` | `client_registry::fetch_metadata_document()`, `jwt_bearer_grant::fetch()` | requires `lib/filelib.php` before use |
| `moodleform` | `classes/form/admin_keys_form.php`, `classes/form/client_form.php` | each requires `lib/formslib.php` |
| `admin_externalpage_setup()` | `admin/keys.php`, `admin/clients.php` | require `lib/adminlib.php` |
| `cli_get_params()`, `cli_error()` | `cli/keys.php`, `cli/clients.php` | require `lib/clilib.php` |

Everything else used comes from preloaded libraries or autoloaded classes:

- `get_enrolled_users`, `get_role_users`, `get_users_by_capability`, `role_fix_names`, `get_all_roles`
- `message_send`, `text_to_html`, `address_in_subnet`, `getremoteaddr`, `is_enabled_auth`
- `core_user`, `core_text`, `\cache`, `\core\lock`, `Firebase\JWT`, event classes

No missing require was found. The test suites only exercise the class code, so endpoint scripts were checked by
reading them.

## Round 5: final-review fixes (FINAL-REVIEW.md #4, #5, #8, #17 and the plausible auth items)

### Test results (real tree, plugin version 2026101005)

| Moodle / DB | Result |
|---|---|
| 4.2 / MariaDB 10.11 (`scripts/run-local-tests.sh mariadb`) | `Tests: 284, Assertions: 1767, Errors: 2` |
| 4.5 / PostgreSQL 18rc1 (isolated compose project `mcpauth`) | `Tests: 283, Assertions: 1758, Errors: 2, Failures: 2` |

The remaining errors and failures are all in other owners' in-progress work:

- `activity_service_test` add_module tests: wrapper owner.
- `mcp_hardening_test::test_run_tool_applies_context_restriction` on 4.5: team lead.

Every auth, OAuth, admin-key, jwt and review-regression test passes on both versions. phpcs shows only long-line
warnings in old method signatures.

### #4: token hashing migration made bulletproof (deploy-critical)

- The hashing loop moved out of step 2026101000 into its own step **2026100900**, which does nothing else. Its
  savepoint follows immediately. Sites already past 2026101000 (they ran the old combined step) skip it, so they are
  never hashed twice.
- **Code:** `webservice_mcp_hash_stored_tokens()` in the new `db/upgradelib.php`.
  - Returns immediately when config `webservice_mcp/tokenshashed` is set.
  - Otherwise hashes every `credential.token` and `oauth_code.code`, and sets `tokenshashed=1` inside the same
    delegated transaction.
- **Failure modes:**
  - A crash mid-loop rolls back everything, so the re-run starts clean.
  - A crash after the commit but before the savepoint finds the flag set, so the re-run does nothing.
- **New `db/install.php`:** sets `tokenshashed=1` and creates the signing secret on fresh installs.
- **Test** `review_fixes_test::test_token_hashing_migration_is_idempotent`:
  - a fresh install is already flagged;
  - a plaintext legacy row is hashed once;
  - a second run leaves the hash unchanged;
  - the original plaintext still resolves.

**Production deploy (2026050101, 844 credentials):** run the upgrade in maintenance mode. Between the code swap and
the upgrade, web requests are not blocked; new code against old data would fail lookups or store tokens that the
migration then hashes again.

```bash
sudo -u nginx php admin/cli/maintenance.php --enable
# deploy code
sudo -u nginx php admin/cli/upgrade.php --non-interactive
sudo -u nginx php admin/cli/maintenance.php --disable
```

Existing clients keep authorizing after the upgrade: step 2026101006 adds the redirect host of every non-revoked
OAuth client to `allowedredirecthosts`, keeping the defaults (see Round 6). On production that adds `mofeed.info` for
client "mofeed". After the upgrade, review the setting and remove any host you don't recognise.

### #5: anonymous endpoints never fetch metadata documents

`service::authenticate_client()` now uses `client_registry::get_client()` only. The token and revoke endpoints never
fetch or create clients; metadata-document clients are stored when a signed-in user authorizes them.

Test `oauth_service_test::test_token_endpoint_never_fetches_metadata_documents`: the fetcher fails the test if
called; both endpoints return `invalid_client`; no client row is created.

### #8: password events mirror core

`observer::user_password_updated()` revokes only when one of these holds:

- `$CFG->passwordchangetokendeletion` is set (the same policy core applies to web service tokens);
- it's a forgotten-password reset (`other['forgottenreset']`);
- someone else changed the password (`userid > 0` and `userid != relateduserid`).

So a login re-hash (no acting user) and a user's own change keep connections by default.

Test `review_fixes_test::test_password_events_mirror_core` covers all five cases. `auth_admin_test` now performs the
change as an administrator.

### #17: the authorization code is burned before the issuing transaction

`token_issuer::exchange_authorization_code()` writes `used=1` before the transaction; only `familyid` and the token
issue are transactional. A user who fails the eligibility re-check therefore cannot retry the code.

Test `oauth_service_test::test_failed_eligibility_still_burns_code`: capability removed → `invalid_grant`; capability
restored → still `invalid_grant`; the code row has `used=1`.

### Auto pre-approval tightened

New `preapproval_service::may_auto_approve(stdClass $client, string $secfetchsite): bool`. Consent is skipped only
when:

- the client is not dynamic (DCR clients never qualify);
- metadata-document clients are additionally listed in the new setting `preapprovalclientids`;
- the browser sent `Sec-Fetch-Site: none` or `same-origin`. A missing header, `same-site` or `cross-site` shows the
  consent page.

`oauth/authorize.php` calls it before `find()`.

Practical effect: an authorize request that a browser opens from claude.ai is `cross-site`, so the user sees the
consent page once more and approves with one click. Silent approval now only happens for typed or same-site
navigations by verified clients. This is the intended trade-off.

Test: `review_fixes_test::test_auto_approval_requires_verified_client_and_same_site`.

### Issuer dominance check

`admin_key_service::check_target()` returns the new reason `privileged` when a non-site-admin issuer targets a user
holding any of these at system level:

- `webservice/mcp:issueforothers`
- `moodle/site:config`
- `moodle/user:loginas`

It applies to keys and pre-approvals. Test: `review_fixes_test::test_issuer_dominance_check`.

### ID-JAG user mapping

`jwt_bearer_grant::match_user()` tries immutable ids first: `oid`, then `sub`, through `auth_oidc_token`. Only after
that does it use `emausermatchfield`:

| Mode | Claims tried |
|---|---|
| `email` | the `email` claim, only when `email_verified` is `true` (`upn` and `preferred_username` are no longer treated as email) |
| `username` | `preferred_username`, then `upn` |
| `idnumber` | `sub`, then `oid` |

Test `jwt_bearer_grant_test::test_user_mapping_prefers_oid_and_requires_verified_email`:

- an unverified or missing `email_verified` is rejected;
- with an `auth_oidc_token` table (created in the test), the `oid` maps to the right user even when the email claim
  names someone else.

### Signing secret created up front

`webservice_mcp_ensure_signing_secret()` runs in `db/install.php` and in upgrade step **2026101005**, so
`signer::secret()` never creates it lazily under concurrent requests. Test:
`review_fixes_test::test_signing_secret_created_at_install`.

### Shared access re-check for tickets, cron tasks and the transport

`\webservice_mcp\local\auth\service_access`:

- `problem(int $userid, int $serviceid, ?string $familykey = null, bool $checkip = true): ?string`
- `assert(...)`, which throws `\webservice_access_exception`.

`credential_manager::assert_service_access(int $userid, int $serviceid, ?string $familykey = null, bool $checkip =
true): void` delegates to `assert()`. New helper: `credential_manager::find_active_in_family()`.

Checks, with their problem codes:

| Code | Meaning |
|---|---|
| `servicenotavailable` | Service missing or disabled |
| `userinactive` | User deleted, suspended, unconfirmed, or nologin |
| `missingrequiredcapability` | User lacks the service's required capability |
| `usernotallowed` | Restricted service: no `external_services_users` row |
| `invalidtimedtoken` | Restricted service: the row's `validuntil` has passed |
| `invalidiptoken` | Restricted service: IP restriction fails (only when `$checkip`) |
| `credentialrevoked` | The family has no active credential owned by `$userid` |
| `oauthdisabled` | OAuth credential and `oauthenabled=0` |
| `clientrevoked` | OAuth credential and the client is revoked |

- `transport_identity::resolve()` uses it, looking up the service id by the credential's service shortname.
- Callers: `tickets::redeem` (files owner, with `$checkip=true`) and `tasks::assert_still_authorised` (team lead,
  with `$checkip=false` in cron).
- Test: `review_fixes_test::test_service_access_problems` covers every code. `auth_admin_test` now creates the
  service its transport identity test needs, since a credential for a nonexistent service no longer resolves.

### Files

- **New:** `db/upgradelib.php`, `db/install.php`, `classes/local/auth/service_access.php`,
  `tests/review_fixes_test.php`
- **Changed:**
  - `db/upgrade.php` (steps 2026100900 and 2026101005; loop removed from 2026101000), `db/install.xml` (VERSION
    only), `version.php`
  - `classes/observer.php`, `classes/local/auth/credential_manager.php`, `transport_identity.php`,
    `preapproval_service.php`, `admin_key_service.php`
  - `classes/local/oauth/service.php`, `token_issuer.php`, `jwt_bearer_grant.php`
  - `oauth/authorize.php`, `settings.php`, `lang/en/webservice_mcp.php` (appended, plus the corrected
    `emausermatchfield_desc`)
  - Tests: `oauth_service_test`, `jwt_bearer_grant_test`, `auth_admin_test`

## Round 6: production redirect hosts preserved (upgrade step 2026101006)

### The problem

On learn.aspireschool.org, 13 of the 14 OAuth clients redirect to claude.ai, but client "mofeed" redirects to
`https://mofeed.info/api/remote-mcp/callback`. The new redirect-host allowlist would have blocked it on
re-authorization.

### The fix

Upgrade step **2026101006** calls `webservice_mcp_seed_redirect_hosts()` (in `db/upgradelib.php`):

- It starts from the configured `allowedredirecthosts`, or the defaults (claude.ai, claude.com, localhost, 127.0.0.1,
  [::1]) when unset, as on production.
- It appends the redirect host of every non-revoked OAuth client: lowercased, brackets ignored for comparison,
  without duplicates.
- Revoked clients are not added.
- It is idempotent.

After the upgrade, production's list will be the five defaults plus `mofeed.info`. The step adds hosts of all active
clients, self-registered ones included. Review the setting after the upgrade and remove any host you don't
recognise.

### Test

`review_fixes_test::test_upgrade_seeds_redirect_hosts_of_existing_clients`:

- an active client on mofeed.info is added;
- the defaults are kept and claude.ai appears once;
- a revoked client's host is not added;
- running it again changes nothing;
- that client then passes authorize validation for its mofeed.info redirect URI.

### Production note

`$CFG->passwordchangetokendeletion = 0` on production. After the #8 fix, a user changing their own password doesn't
revoke MCP connections, the same as core web service tokens. Admin resets and forgotten-password resets still
revoke.

### Results (plugin version 2026101006)

| Moodle / DB | Result |
|---|---|
| 4.2 / MariaDB 10.11 | `Tests: 285, Assertions: 1779, Errors: 1` |
| 4.5 / PostgreSQL | `Tests: 285, Assertions: 1768, Errors: 2, Failures: 2` |

All failures are in other owners' in-progress tests: `activity_service_test` (add_module) and, on 4.5,
`mcp_hardening_test::test_run_tool_applies_context_restriction`. Every auth test passes.

## Round 7: rate limits in the database, admin key label column, showhighrisktools removed (step 2026101007)

Production runs 2026101006. Every operation in step 2026101007 is additive or idempotent:

- creating the table is guarded by `table_exists`;
- adding the field and index is guarded by exists checks;
- the label copy only fills NULL labels;
- `unset_config` is harmless if the setting is absent.

### Schema and data

- **New table `webservice_mcp_ratelimit`:** `id`, `bucket` char(64) (SHA-256 of the limited key; registration uses
  `registration|<ip>`), `timecreated`. Indexes `bucket_time_idx (bucket, timecreated)` and `timecreated_idx`.
- **New column `webservice_mcp_credential.label`** char(255) null, with index `label_idx`.
  - The upgrade runs `webservice_mcp_copy_admin_key_labels()`: `label = name` for tokentype 3 rows without a label.
    `name` is untouched.
- **Removed:**
  - `unset_config('showhighrisktools', 'webservice_mcp')`;
  - the setting from `settings.php` and its two lang strings;
  - the `oauth_ratelimit` cache definition and its lang string.

### Code

- **`client_registry::enforce_registration_rate_limit()`:** counts rows for the bucket in the last hour (sliding
  window) and refuses the 21st with HTTP 429. Otherwise it inserts a row.
- **`classes/task/cleanup.php`:**
  - deletes rate-limit rows older than 2 hours;
  - calls `\webservice_mcp\local\files\export_service::purge($now)` for async exports older than 24 hours
    (files-builder's method).
- **Admin keys:**
  - `credential_manager::issue_admin_credential()` stores `label` (and still `name`).
  - `admin_key_service::list_keys()`, `count_keys()` and `revoke_keys()` filter on `c.label`.
  - The admin page, CLI `--list` and the connected-apps list show the label.
- **No reads of `showhighrisktools` remain.** `tool_provider_test` and `discovery_service_test` (not my files) still
  set it to 0 to prove a leftover value has no effect; they pass.

### Tests (`review_fixes_test`)

- **`test_registration_rate_limit_survives_cache_purge`:**
  - 20 registrations, then `cache_helper::purge_all()`; the 21st still gets 429;
  - hits older than the window stop counting, and the cleanup task deletes them.
- **`test_admin_key_label_migration_and_filter`:**
  - new keys get a label;
  - the migration fills a missing label from `name` and leaves `name` and non-admin rows untouched;
  - label filter, count and revoke work after migration and leave an OAuth token with the same `name` alone.
- **`test_showhighrisktools_setting_removed`:** the plugin settings page contains `oauthenabled` but not
  `showhighrisktools`, and the lang string is gone.

### Results (plugin version 2026101007)

| Moodle / DB | Result |
|---|---|
| 4.2 / MariaDB 10.11 (`scripts/run-local-tests.sh mariadb`) | `Tests: 293, Assertions: 1817, Errors: 1, Failures: 1` |
| 4.5 / PostgreSQL 18rc1 (isolated compose project `mcpauth`, after 5432 was free) | `Tests: 293, Assertions: 1820, Errors: 1, Failures: 1` |

Both failures are in files-builder's in-progress `files_export_test`:

- `test_assignment_export_builds_in_cron`: `stored_file::list_files()` ArgumentCountError from the test's zip check.
- `test_purge`: expected 2, got 4. That test calls `export_service::purge()` directly, not the cleanup task.

All auth tests pass, including the three new ones. phpcs on this batch's files reports no errors or warnings.

## Round 8: short file links table (step 2026101008)

### Schema

New table `webservice_mcp_link` (proposed to files-builder; their confirmation of the columns was still pending
when this was written):

| Column | Type | Notes |
|---|---|---|
| `id` | int seq | primary key |
| `linkid` | char(32) | unique index `linkid_uix` |
| `payload` | text | the signed ticket |
| `userid` | int | key `userid_fk` |
| `expiresat` | int | index `expiresat_idx` |
| `timecreated` | int | |

The step only creates the table (guarded by `table_exists`), so it is safe on production's 2026101007 data.
`install.xml` and `version.php` are at 2026101008.

### Related changes

- `classes/task/cleanup.php` deletes rows with `expiresat < now`.
- The observer deletes a user's links on `user_deleted`.
- The privacy provider declares the table (`userid`, `expiresat`, `timecreated`), exports `timecreated` and
  `expiresat` but never the payload (a bearer ticket), and deletes by user.
- 5 lang strings were inserted at their alphabetical positions; the file is now sorted.

### Test

`review_fixes_test::test_link_table_upgrade_cleanup_and_privacy`:

- puts the site back to 2026101007 (drops the table, sets version 2026101007) with an existing admin key;
- runs `xmldb_webservice_mcp_upgrade(2026101007)`;
- checks the table exists, the version is 2026101008, and the key still resolves and is found by label;
- checks `linkid` is unique;
- checks cleanup removes only the expired link;
- checks privacy context lookup and deletion remove the user's links.

### Results (plugin version 2026101008)

| Moodle / DB | Result |
|---|---|
| 4.2 / MariaDB 10.11 (`scripts/run-local-tests.sh mariadb`) | `Tests: 295, Assertions: 1846, Failures: 2` |
| 4.5 / PostgreSQL 18rc1 (isolated compose project `mcpauth`) | `Tests: 295, Assertions: 1849, Failures: 1` |

The failures are in files-builder's in-progress short-link and export work:

- `tool_annotations_test::test_every_listed_tool_is_annotated`: the export tools' annotations (both versions).
- `files_access_test::test_text_over_cap_is_paged_with_download_hint`: expects a `pluginfile.php?ticket=` hint (4.2
  only).

All auth tests pass. phpcs on this batch's files is clean.

The `pathtopdftotext` setting was not added: files-builder hadn't asked for it when this was written.

## Known limitations

Resolved in round 3: strict refresh rotation (now a grace window), cache-only jti storage (now a table), hard-coded
retention periods and listing caps (now settings with pagination), and the missing pre-registration UI. Resolved in
round 4: secret rotation. Resolved in round 7: cache-only registration rate limits (now a table) and labels stored in
`name` (now a `label` column).

What remains:

1. **No `logo_uri`:** a metadata document's `logo_uri` isn't shown on the consent page, because a remote image from an
   unverified client aids phishing and leaks the user's IP.
2. **Upgrade-time provisioning gap:** users already on the connector service at upgrade time are recorded by the
   upgrade step and the hourly sync. An admin removal made before either has run may be undone once.
