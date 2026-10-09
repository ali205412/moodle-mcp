---
name: moodle-file-transfer
description: Upload, download, attach, export and back up Moodle files of any size through the Moodle MCP server.
---

# Moodle file transfer

Everything runs as the signed-in Moodle user. If a call is refused, the user lacks that permission in Moodle.

## Find files

- `file_list` with `courseid` lists every file area in a course; add `cmid` for one activity, or nothing for your private files.
- File results carry a `uri` like `moodle://file/{contextid}/{component}/{filearea}/{itemid}/{path}`. Any `fileurl` returned by Moodle functions (e.g. from `core_course_get_contents`) also works wherever a file is expected.

## Read or download

- Small files: `file_read` returns text inline, images as images, other binaries as base64.
- Large files: `file_get_download_url`, then fetch it outside the model context:
  `curl -fL -o "local-name.ext" "<url>"`
  Links expire (about 15 minutes) and support HTTP Range, so `curl -C -` resumes.
- Whole course: `export_course_content` (zip). All assignment submissions: `export_assignment_submissions` (zip).
- Course or activity backup: `backup_create`, poll `backup_status` until complete, then download the returned link.

## Upload

1. Small content (under ~15 MB): `file_upload` with `content_base64` or `content_text`.
2. Anything larger, or local files: `file_create_upload_url`, then
   `curl -fT ./path/to/file "<upload_url>&filename=file.ext"`
   or multipart for several files: `curl -F "file=@a.pdf" -F "file=@b.pdf" "<upload_url>"`.
3. Public web file: `file_upload_from_url`.

Every upload returns a `draftitemid`. Reuse the same draft id to collect several files.

## Attach the draft to its destination

| Destination | How |
| --- | --- |
| New File/Folder resource | `wrapper_course_add_module` with `modulename` resource/folder and `options.files = draftitemid` |
| SCORM / H5P package | `wrapper_course_add_module` with `options.packagefile = draftitemid` |
| Assignment submission | `mod_assign_save_submission` with `plugindata.files_filemanager = draftitemid` |
| Assignment feedback files | `mod_assign_save_grade` with `plugindata.files_filemanager = draftitemid` |
| Forum post attachments | `mod_forum_add_discussion` / `mod_forum_add_discussion_post` option `attachmentsid` |
| Glossary / workshop | `mod_glossary_add_entry` / `mod_workshop_add_submission` `attachmentsid` |
| Database entry file field | `mod_data_add_entry` field value = draftitemid |
| Private files | `core_user_add_user_private_files` or `file_save_draft` into user/private |
| Course image | `file_set_course_image` |
| Profile picture | `core_user_update_picture` |
| Any writable area | `file_save_draft` (merge by default; `replace` deletes what is not in the draft) |
| Restore a course | `restore_from_draft` with the uploaded .mbz |

Use `wrapper_moodle_api_describe` to confirm exact parameters before calling a Moodle function.
