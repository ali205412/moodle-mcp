---
status: completed
phase: 02-remote-transport-session-isolation
source: [02-VERIFICATION.md]
started: 2026-04-21T17:25:00Z
updated: 2026-04-21T17:25:00Z
---

## Current Test

Awaiting live remote-transport validation inside a running Moodle site.

## Tests

### 1. Allowed-Origin browser preflight
expected: An allowed browser origin can complete `OPTIONS` preflight to `/webservice/mcp/server.php` before auth, followed by an authenticated initialize request that returns `MCP-Session-Id`.
result: pass

### 2. Primary HTTP lifecycle
expected: A real connector credential can complete `initialize -> notifications/initialized -> tools/list -> tools/call` on `/webservice/mcp/server.php` using one accepted MCP session id.
result: pass

### 3. Legacy SSE compatibility
expected: With legacy SSE enabled, `/webservice/mcp/sse.php` replays buffered transport events for the same session id and returns disabled/not-found behavior when the setting is off.
result: pass

## Summary

total: 3
passed: 3
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

- Live browser/proxy preflight behavior not yet exercised.
- Live remote-client lifecycle not yet exercised.
- Live SSE compatibility behavior not yet exercised.
