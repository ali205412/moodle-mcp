# Phase 9: Full Coverage & UI Parity - Research

**Researched:** 2026-04-22  
**Confidence:** HIGH

## Findings

- `question_type::save_question()` in `tmp/moodle/question/type/questiontypebase.php` is the stable, versioned write path for question authoring. It exists in the supported 4.2+ line and creates new `question`, `question_bank_entries`, and `question_versions` records while delegating type-specific option saving.
- `question_bank::load_question_data()` in `tmp/moodle/question/engine/bank.php` exists in 4.2+ and is the clean way to fetch the current authored version before creating a new edited version.
- Question category CRUD in Moodle 4.2-4.4 still lives in `qbank_managecategories\question_category_object` and helper functions, while 4.5 adds `core_question\category_manager`. For 4.2+ compatibility, wrappers should avoid the new 4.5-only class and instead use the older cross-version logic grounded in `tmp/moodle/question/bank/managecategories/classes/question_category_object.php` and `tmp/moodle/question/bank/managecategories/classes/helper.php`.
- Question import is feasible without browser automation because `qformat_default::importprocess()` in `tmp/moodle/question/format.php` still provides a file-driven import pipeline, and standard formats such as GIFT and XML are normal qformat plugins.
- Question preview is not a harvested external API, but `qbank_previewquestion\helper` in `tmp/moodle/question/bank/previewquestion/classes/helper.php` provides stable preview URLs for Moodle’s own preview flow.
- Gradebook category editing should keep using `grade_edit_tree::update_gradecategory()` from `tmp/moodle/grade/edit/tree/lib.php`, which already encapsulates category + grade-item updates and exists in Moodle 4.2+.
- Manual grade-item CRUD is still UI-driven in Moodle 4.2+; the stable reference flow is `tmp/moodle/grade/edit/tree/item.php`, which uses `grade_item::set_properties()`, `insert()`, `update()`, `set_parent()`, `set_hidden()`, `set_locktime()`, and `set_locked()`.
- Badge lifecycle and metadata parity are achievable plugin-first because `core_badges\badge` in `tmp/moodle/badges/classes/badge.php` exposes `create_badge()`, `delete()`, `make_clone()`, `add_related_badges()`, `delete_related_badge()`, `save_alignment()`, and `delete_alignment()`. Message editing is handled by `update_message()` in current Moodle and by direct badge fields plus `save()` in older supported branches.
- Manual award/revoke remains UI-driven but safe to wrap because the exact action path is encapsulated in `process_manual_award()` and `process_manual_revoke()` in `tmp/moodle/badges/lib/awardlib.php`.

## Implications

- Phase 9 can add real parity without introducing browser automation or a companion service.
- Question-bank wrappers should support a constrained set of authored qtypes first, using Moodle’s native question versioning, rather than attempting a generic unsafe “submit any form blob” executor.
- Gradebook wrappers should focus on manual items and category setup structure, where the largest parity gap remains after harvested externals.
- Badge wrappers can cover most of the remaining high-value admin flows directly through the core badge model.
- Plugin-specific parity outside these domains still needs an explicit audit artifact so unsupported areas are documented, not implied away.
