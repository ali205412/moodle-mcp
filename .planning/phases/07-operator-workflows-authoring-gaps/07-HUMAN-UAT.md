# Phase 07 Human UAT

## Required Checks

1. Connect with a manager-level Moodle account and confirm operator tools now group into users, enrolments, groups, cohorts, roles, categories/courses, authoring, competencies, and privacy surfaces.
2. Confirm a non-privileged user does not see operator-only tools they cannot actually use.
3. In an editable course, call the new wrappers for section add, section visibility, module visibility, module duplicate, and module delete, then confirm the course state updates correctly in Moodle.
4. Trigger a privacy-request workflow and confirm the discovery metadata correctly signals follow-up polling via `tool_dataprivacy_get_data_request` or `tool_dataprivacy_get_data_requests`.
5. Validate representative harvested flows for cohort membership, role assignment, manual enrolment, and category/course creation with real permissions.
