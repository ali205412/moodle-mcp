# Local patches for the Moodle 5.3 upgrade (all verified on the staging copy)

| Plugin | Version used | Patch |
|---|---|---|
| format_tiles | github.com/leonstr/moodle-format_tiles master (3ef1cfd) | Community fork carrying the two 5.2+ fixes (Mustache engine class, modal factory). |
| theme_academi | v5.2 (2026042900) | `theme_academi/`: import 5.3 design-system/font-stack SCSS (`includes.scss.patch`), `moodle53.scss` (drawer toggles below the 180px header, switch font, 4.5 login card), `templates/core/login_layout.mustache` (centred login, no 5.3 welcome panel). |
| local_o365 | 5.2.1 (2026042001) | `classes/webservices/read_assignments.php`: `external_format_text(` -> `\core_external\util::format_text(` (throws on 5.3). |
| local_aspireparent | site copy | `classes/external/get_mentee_quiz_attempts.php`: `\quiz_attempt::FINISHED` -> `\mod_quiz\quiz_attempt::FINISHED` (alias removed in 5.0; works on 4.5 too). |
| mod_chat, mod_survey | moodlehq/moodle-mod_chat, moodle-mod_survey main | Keep chat (275 messages) and survey data; core removed both in 5.0. |
| lightboxgallery, Microsoft 365 suite | latest from plugins directory (4.5.4 / 5.2.x) | none |
| aspireparent (security) | site copy | `aspireparent-grade-permission.patch` (already live on 4.5). |
