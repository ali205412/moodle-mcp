---
phase: 04-permission-gated-discovery-safety
plan: 01
subsystem: discovery-policy
tags: [moodle, permissions, risk, discovery]
requires:
  - phase: 03
    provides: harvested catalog projection
provides:
  - Eligibility resolver for safe discovery-time filtering
  - Risk analyzer for confirmation and safety metadata
  - Site policy toggle for high-risk discovery
affects: [discovery, settings]
tech-stack:
  added: [eligibility resolver, risk analyzer, site policy toggle]
  patterns: [safe capability filtering, risk-driven confirmation metadata]
key-files:
  created:
    - classes/local/discovery/eligibility_resolver.php
    - classes/local/discovery/risk_analyzer.php
  modified:
    - settings.php
    - lang/en/webservice_mcp.php
key-decisions:
  - "Discovery only hard-filters when capability evaluation is safe in the current restricted context."
  - "High-risk discovery visibility is a site policy, defaulting to visible."
patterns-established:
  - "eligibility resolver separates resolved capability checks from deferred call-time checks"
  - "risk analyzer derives low/medium/high/critical levels from mutability, destructive hints, and capability risk bits"
requirements-completed: [PERM-01-foundation, PERM-04-foundation]
duration: 29min
completed: 2026-04-21
---

# Phase 4: Permission-Gated Discovery & Safety Summary

**Wave 1 discovery eligibility and risk foundations**

## Accomplishments

- Added a discovery eligibility resolver that only hides tools when capability evaluation is safe in the current restricted context.
- Added a risk analyzer that derives structured risk and confirmation metadata from mutability, destructive hints, and Moodle capability risk bits.
- Added a site policy setting to hide high-risk tools from discovery when desired.

## Files Created/Modified

- `classes/local/discovery/eligibility_resolver.php`
- `classes/local/discovery/risk_analyzer.php`
- `settings.php`
- `lang/en/webservice_mcp.php`

## Next Phase Readiness

- The projection layer can now apply discovery filtering and risk metadata without rebuilding the catalog model.

---
*Phase: 04-permission-gated-discovery-safety*
*Completed: 2026-04-21*
