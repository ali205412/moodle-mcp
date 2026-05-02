# Phase 6: Activity Workflows & Wrapper Foundation - Research

**Researched:** 2026-04-21  
**Confidence:** HIGH

## Findings

- Assignment workflows already expose read, submission, and grading-oriented externals.
- Forum workflows already expose discussion/post creation, update, delete, subscription, and access-information helpers.
- Quiz, workshop, and feedback each already expose substantial attempt/submission workflows.
- Standard modules like chat, glossary, wiki, data, choice, survey, scorm, h5pactivity, bigbluebutton, and lti also expose meaningful externals when installed.

## Implication

- Phase 6 should be workflow-curation first, not blanket wrapper creation.
- The missing architectural piece is a reusable wrapper definition/manager layer so future UI-only gaps can be added without inventing a new pattern.
