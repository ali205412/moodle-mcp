# Phase 08 Human UAT

## Required Checks

1. Install the plugin on Moodle 4.2, 4.3, 4.4, and 4.5 test sites and confirm bootstrap, tools/list, and one wrapped authoring call succeed.
2. Confirm a discovery response now includes an audit id and that the same audit id can be found in the plugin audit table.
3. Confirm a mutating wrapped tool call returns an audit id and leaves a matching audit record with the tool name and outcome.
4. Confirm discovery remains paginated and responsive on a plugin-heavy site or seeded test site with a large external-function surface.
5. Confirm optional surfaces such as Data Privacy only appear when the target Moodle version/site actually exposes them.
