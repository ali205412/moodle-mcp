# Phase 10 Verification (Nyquist Compliance)

## Phase Goal
Activity Authoring & Protocol Expansion

## Observable Truths
- Claude can author fully functional activities that appear in the course and gradebook.
- Claude can discover and safely execute any Moodle API function.
- Memory table schema is defined and deployed.
- Memory CRUD service validates user ownership of memories.
- Manager maps new tool wrappers for execution.
- Transport server supports MCP native resources and prompts routing.

## Automated Verification Tests
Run the following commands to verify the newly built subsystems:

```bash
# Verify schema migrations
php admin/cli/upgrade.php --non-interactive

# Verify Memory Service
vendor/bin/phpunit --filter memory_service_test

# Verify Activity Service
vendor/bin/phpunit --filter activity_service_test

# Verify Discovery Service
vendor/bin/phpunit --filter discovery_service_test

# Verify MCP Transport Server integration
vendor/bin/phpunit --filter transport_server_test
```

## System Integration Verification
After passing unit tests, perform an end-to-end integration check:
1. Execute `php admin/cli/cfg.php --component=webservice_mcp --show` and ensure no fatal errors when loading tools config.
2. Execute the discovery API locally (via a sample CLI script or test harness) to verify `search_api` correctly returns a subset of Moodle's 800+ functions without memory exhaustion.
3. Call `create_module()` wrapper and observe the course page rendering the new module properly.
