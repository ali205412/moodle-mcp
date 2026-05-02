# Phase 8: Compatibility, Audit & Release Hardening - Patterns

## Existing Patterns To Reuse

- `classes/local/auth/credential_manager.php`
  Put version fallbacks close to the compatibility-sensitive call site.
- `classes/local/transport/server.php`
  Keep audit and response-shaping work in the transport layer so it applies to both harvested and wrapped tools.
- `tests/launch_test.php`, `tests/sse_transport_test.php`, `tests/transport_server_test.php`
  Extend the existing transport/auth coverage instead of inventing a second test harness.
- `tests/tool_provider_test.php`
  Keep large-site and version-sensitive discovery assertions in the projection layer tests.

## Pattern Decision

Phase 8 does not introduce a companion service or external relay. Hardening stays plugin-first and source-backed.
