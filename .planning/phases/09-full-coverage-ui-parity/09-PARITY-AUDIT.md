# Phase 09: Parity Audit

## Wrapped In Phase 9

### Question Bank
- Category create, update, delete
- Question move and delete
- Supported authored qtype version creation and editing for:
  - `shortanswer`
  - `truefalse`
  - `essay`
  - `description`
- Native preview URL generation
- Text import through `gift` and `xml`

### Gradebook
- Manual grade item create, update, move, delete
- Grade category update, move, delete

### Badges
- Badge create, update, message update, delete, duplicate
- Related badge add/remove
- Alignment add/remove
- Manual award and revoke

## Still Harvested Rather Than Wrapped

- Existing question-bank externals such as flagging, random summaries, status, tagging, and column preferences
- Existing gradebook externals such as grade tree readout, grade category creation, grade-item listing, grader panels, and report data
- Existing badge externals such as get badge, get user badges, enable, and disable

## Explicitly Still Unsupported

### Question Bank
- Full generic authoring for every installed qtype remains unsupported because Moodle’s form payloads are deeply qtype-specific and a generic blob executor would violate the plugin’s safety model.
- Archive/package imports beyond standard GIFT/XML text flows remain unsupported in wrappers for the same reason.

### Gradebook
- Advanced calculation-editor authoring, CSV import wizards, and report-specific bulk UI actions remain unsupported as typed wrappers.
- Outcomes-specific setup flows are still only partially covered through existing Moodle tools and grade-item primitives.

### Badges
- Image upload/editing is not wrapped yet.
- Backpack/OAuth/export flows are not wrapped.
- Criteria authoring beyond manual-award execution remains unwrapped.

### Plugin-Specific UI
- Third-party plugin UI wrappers are still demand-driven and are not generalized here.
- Browser automation and arbitrary form execution remain intentionally out of scope.

## Rationale

The remaining unsupported areas are the places where Moodle still relies on highly dynamic, form-specific, or OAuth/browser-heavy flows that do not have a stable plugin-first execution primitive. They are documented explicitly so parity claims stay honest.
