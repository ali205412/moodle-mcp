---
name: moodle-api-gateway
description: Reach any of Moodle's 800+ functions safely with search, describe and execute.
---

# Moodle API gateway

1. `wrapper_moodle_api_search` with 2-4 keywords (try synonyms: "enrol", "participants", "users"). Filter with `component` (e.g. `mod_assign`) or `type` (`read`/`write`).
2. `wrapper_moodle_api_describe` on the best candidates to get the exact input schema and an `exampleArgs` skeleton.
3. `wrapper_moodle_api_execute` with `functionname` and `params` matching the schema exactly (Moodle rejects unknown keys).

Rules:
- Prefer read functions first to collect IDs; never guess IDs.
- Lists are usually arrays of objects even for one item (e.g. `courses: [{...}]`).
- Errors include Moodle's error code (`nopermissions`, `invalidparameter`, `errorcoursecontextnotvalid`); fix the input or tell the user they lack permission.
- Writes change real school data: confirm intent with the user for bulk or destructive changes.

See `references/common-functions.md` for frequently used functions.
