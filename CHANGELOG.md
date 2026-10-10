# Change Log
All notable changes to this project will be documented in this file.

## Version 0.10.0 (2026101100)
- UI bridge: Claude can use any Moodle page as the signed-in user, including the ~395 installed plugins with no API
  (blocks, reports, admin tools, course formats, local plugins) and admin pages for site admins:
  - `moodle_page_view` returns the page's text, alerts, tabs, numbered links and every form field (labels, values,
    options, editors, dates, file pickers); `moodle_page_action` follows state-changing links; `moodle_page_submit`
    fills in and submits forms with Moodle's own validation and permission checks; files and exports the page returns
    are saved to the user's draft area
  - real Moodle sessions created by `complete_user_login` from one-time loopback-bound keys (30 s); per-connection
    session cache, killed on revocation; same-origin URL policy with deny list and re-checked redirects; GET needs
    read scope, POST or sesskey links need write scope; course-restricted tokens are refused; sesskey never leaves the
    server; two concurrent fetches site-wide; page path recorded in the audit log
  - setting `uibridge`, capability `webservice/mcp:uibridge`
- 15 targeted tools mirroring UI pages' own checks: activity settings get/update (module form validation), section
  update, admin settings search/get/set (per-setting validation, config log), role overrides get/set (override /
  safeoverride rules), enrolment method add/update/delete/status, logs and participation reports, course reset
- Activity creation now runs the module form's full validation (required fields enforced as in the UI)

## Version 0.9.5 (2026101011)
- Moodle Explorer (MCP App) redesign, audited screen by screen in the MCP Apps reference host:
  - "Ask Claude" works in SDK hosts (`ui/message` content is an array per the spec types); per-file "Ask Claude"
  - keyboard and screen-reader accessible (real buttons, focus rings, labelled sections, live region)
  - dark and light themes follow the host (with fallbacks), host fonts, locale and time zone
  - panel height fits content; full-screen toggle; host-mediated downloads (`ui/download-file`) with link fallback
  - course list: favourites first, progress bars, completed/hidden badges, last opened, filter
  - course view: collapsible sections with summaries, filter with highlighting, upcoming/overdue deadlines,
    activity icons and type names, completion ticks, dates, open in Moodle, file type badges and readable sizes
  - tells the model which course is open (`ui/update-model-context`); skeleton loading; error states with recovery
- `moodle_explorer` returns course/deadline links, activity type names, completion, dates, visibility and plain-text
  section summaries

## Version 0.9.4 (2026101010)
- OAuth: concurrent refreshes of a pre-0.9.0 refresh token no longer log the user out (legacy tokens join a token
  family on first rotation, so the grace window applies)
- Tool errors explain themselves: parameter validation reasons (including native Moodle functions via the gateway)
  reach the client; every wrapper input error names what was wrong and what is accepted
- `file_list` with component=user filearea=draft lists your draft areas; unreadable listings say what was looked up
- `moodle_explorer` opens a course when only `courseid` is passed
- Audit: failed outcomes store their error message (`detail` column); rejected bearer tokens are audited again
  (`invalid_token`, `token_expired`, `token_revoked`)

## Version 0.9.3 (2026101009)
- Page images of Office files: retry a conversion that core cached as failed (e.g. one attempted before the
  converter worked) instead of returning the stale failure; clearer conversion error messages

## Version 0.9.2 (2026101008)
- `file_read` returns real content: text from docx, pptx (slides + speaker notes), xlsx, odt/odp/ods, rtf and html in
  pure PHP; PDF text via pdftotext; `render: "images"` turns PDF and Office pages into PNG images (Ghostscript or
  poppler, Office via the core document converter)
- Clear error messages from every file tool (no more "error occurred" without developer debugging)
- Short download and upload links (`?t=<id>`, table `webservice_mcp_link`)
- Exports are idempotent: a repeat request returns the existing export (`refresh: true` forces a new one)
- Moodle Explorer app: fixed a JavaScript syntax error that left the app on "Loading"; handles cancelled calls
- Fixed `backup_status`, `backup_create` and `restore_from_draft` failing on a cold request
- Every tool carries a title and complete read-only/destructive annotations

## Version 0.9.1 (2026101007)
- Course content and assignment submission exports build in cron and download as stored files (served by
  X-Accel-Redirect where configured), so they no longer hold a PHP worker; status via `backup_status`
- Client registration rate limit stored in the database (survives cache purges)
- Admin key labels in their own `label` column
- Removed the deprecated `showhighrisktools` setting
- Language strings sorted

## Version 0.9.0 (2026101006)
- MCP 2026-07-28 (stateless, `server/discover`, MRTR) alongside legacy 2024-11-05 to 2025-11-25 sessions
- Resources, resource templates, prompts, completions, MCP Apps (Moodle Explorer), Skills, server card, MCP Tasks
- File tools: list, read, upload (inline, URL, resumable chunked PUT up to 2 GB by default), save draft, delete,
  signed downloads with Range support, course image, exports, async backup and restore
- Gateway search, describe and execute over every function in the connector service; Moodle's own permission
  checks are the boundary
- Tokens stored hashed, PKCE S256 only, refresh rotation with grace window, admin-minted bulk keys (admin page,
  CLI, bulk user action), OAuth pre-approval, client administration and secret rotation, jwt-bearer (ID-JAG) grant
- Security hardening from a pre-production review (XSS, transaction leaks, context and service scoping)

## Version 0.4.1 (2025121302)
- Fix: Simplified `moodle_exception` usage by using global class instead of namespaced version

## Version 0.3.0 (2025121300)
- Initial beta release
- MCP protocol implementation with JSON-RPC 2.0
- Support for initialize, tools/list, and tools/call methods
- Dynamic tool discovery from Moodle external functions
- JSON Schema generation for parameters and return values
- Built-in client class for integration
- Comprehensive test coverage
- CI/CD pipeline with GitHub Actions
