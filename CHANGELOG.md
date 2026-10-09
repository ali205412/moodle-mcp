# Change Log
All notable changes to this project will be documented in this file.

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
