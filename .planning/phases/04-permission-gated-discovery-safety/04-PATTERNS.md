# Phase 04: Permission-Gated Discovery & Safety - Pattern Map

## File Classification

- `classes/local/discovery/eligibility_resolver.php` - discovery filter / policy engine
- `classes/local/discovery/risk_analyzer.php` - metadata/risk engine
- `classes/local/tool_provider.php` - projection adapter
- `classes/local/transport/server.php` - call-time denial formatter / edge integration
- `tests/tool_provider_test.php` - discovery eligibility tests
- `tests/transport_server_test.php` - denial/risk transport tests

## Pattern Assignments

### `classes/local/discovery/eligibility_resolver.php`

- Pattern: explicit discovery filter + call-time hint annotator
- Responsibilities:
  - hide tools when explicit required capabilities fail now
  - annotate call-time checks when deeper boundaries are not safely resolvable
  - discover companion access-information tools

### `classes/local/discovery/risk_analyzer.php`

- Pattern: deterministic risk metadata derivation
- Responsibilities:
  - classify low/medium/high/critical risk
  - set confirmation requirement
  - surface destructive/admin signals

### `classes/local/tool_provider.php`

- Pattern: identity-aware projection
- Responsibilities:
  - project only visible tools
  - attach eligibility/risk metadata
  - keep pagination/grouping stable after filtering

### `classes/local/transport/server.php`

- Pattern: call-time denial enricher
- Responsibilities:
  - preserve authoritative execution checks
  - format clearer restriction reasons in JSON-RPC errors

## Shared Patterns

### Explicit Versus Inferred Eligibility

- Explicit:
  - service scope
  - required capabilities
  - restricted context
  - loginrequired
- Inferred or companion-based:
  - activity access-information helpers
  - group or visibility hints
  - ownership hints from well-known parameter names

### Safety Metadata Without False Authority

- Discovery should expose risk and confirmation metadata.
- Execution remains authoritative and may still deny.

## Notes For Planning

- Do not collapse access-information discovery into hard filtering unless the signal is explicit and safe.
- Keep denial reasons structured so clients can display them or route retries/context selection.
