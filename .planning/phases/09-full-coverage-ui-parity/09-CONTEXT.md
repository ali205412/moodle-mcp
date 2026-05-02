# Phase 9: Full Coverage & UI Parity - Context

## Goal

Close the remaining high-value UI-only Moodle gaps in question bank, gradebook, and badge administration without abandoning the plugin-first model, the per-user permission model, or Moodle-native side effects.

## Baseline

- Phases 1-8 already deliver Moodle login/bootstrap, Streamable HTTP and SSE transport, harvested discovery, permission gating, learner/operator workflows, typed course-authoring wrappers, audit ids, and CI/test automation.
- The biggest remaining parity gap is not harvested API discovery. It is the absence of connector-owned wrappers for UI-only actions in question bank, gradebook setup, and badge management.

## Moodle Sources Checked

- `tmp/moodle/question/type/questiontypebase.php`
- `tmp/moodle/question/type/shortanswer/questiontype.php`
- `tmp/moodle/question/type/truefalse/questiontype.php`
- `tmp/moodle/question/type/essay/questiontype.php`
- `tmp/moodle/question/engine/bank.php`
- `tmp/moodle/question/format.php`
- `tmp/moodle/question/format/gift/format.php`
- `tmp/moodle/question/format/xml/format.php`
- `tmp/moodle/question/bank/previewquestion/classes/helper.php`
- `tmp/moodle/question/bank/managecategories/classes/question_category_object.php`
- `tmp/moodle/question/bank/managecategories/classes/helper.php`
- `tmp/moodle/lib/questionlib.php`
- `tmp/moodle/grade/edit/tree/lib.php`
- `tmp/moodle/grade/edit/tree/item.php`
- `tmp/moodle/lib/grade/grade_item.php`
- `tmp/moodle/lib/grade/grade_category.php`
- `tmp/moodle/badges/classes/badge.php`
- `tmp/moodle/badges/lib/awardlib.php`
- `tmp/moodle/badges/edit.php`
- `origin/MOODLE_402_STABLE:question/bank/managecategories/classes/question_category_object.php`
- `origin/MOODLE_402_STABLE:question/format.php`
- `origin/MOODLE_402_STABLE:question/engine/bank.php`
- `origin/MOODLE_402_STABLE:question/bank/previewquestion/classes/helper.php`
- `origin/MOODLE_402_STABLE:grade/edit/tree/lib.php`
- `origin/MOODLE_402_STABLE:grade/edit/tree/item.php`
- `origin/MOODLE_402_STABLE:badges/classes/badge.php`
- `origin/MOODLE_404_STABLE:question/bank/previewquestion/classes/helper.php`
- `origin/MOODLE_404_STABLE:grade/classes/form/add_item.php`
- `origin/MOODLE_404_STABLE:badges/classes/badge.php`

## Phase Focus

1. Add typed wrappers for the highest-value question-bank authoring and organization flows using stable 4.2+ internals.
2. Add typed wrappers for gradebook setup actions that still depend on the UI, especially manual items and category edits.
3. Add typed wrappers for badge lifecycle, message, relation, alignment, and manual award workflows that are still UI-only.
4. Project the new wrappers into workflow metadata and document the remaining unsupported gaps explicitly instead of hand-waving parity.
