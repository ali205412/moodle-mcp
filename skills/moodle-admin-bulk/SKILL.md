---
name: moodle-admin-bulk
description: Bulk user, enrolment, cohort, group and role operations in Moodle with dry-run first.
---

# Bulk administration

Always run a dry run first: resolve every person and course to IDs, show the table of intended changes and anything ambiguous, and wait for confirmation.

- Find users: `core_user_get_users_by_field` (email, username, idnumber) or `core_user_get_users` with criteria.
- Create or update users: `core_user_create_users`, `core_user_update_users`.
- Enrol / unenrol: `enrol_manual_enrol_users` (roleid 5 = student by default; confirm with `core_role_` functions), `enrol_manual_unenrol_users`.
- Cohorts: `core_cohort_create_cohorts`, `core_cohort_add_cohort_members`, `core_cohort_delete_cohort_members`.
- Groups: `core_group_create_groups`, `core_group_add_group_members`, `core_group_create_groupings`.
- Roles: `core_role_assign_roles`, `core_role_unassign_roles`.

Process in batches of 50-100 and report successes and failures separately. Never delete users unless explicitly asked.
