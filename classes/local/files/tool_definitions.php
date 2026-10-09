<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace webservice_mcp\local\files;

/**
 * Titles, descriptions and input schemas of the file tools.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_definitions {
    /**
     * All definitions keyed by tool name.
     *
     * @return array
     */
    public static function all(): array {
        $id = static fn(string $d): array => ['type' => 'integer', 'minimum' => 1, 'description' => $d];
        $bool = static fn(string $d): array => ['type' => 'boolean', 'description' => $d];
        $str = static fn(string $d, int $max = 1333): array => ['type' => 'string', 'maxLength' => $max, 'description' => $d];
        $uri = $str('File URI from file_list, e.g. moodle://file/123/mod_resource/content/0/notes.pdf');
        $url = $str('A file link on this Moodle site (pluginfile.php, webservice/pluginfile.php, tokenpluginfile.php or '
            . 'draftfile.php), e.g. a fileurl from core_course_get_contents.', 4000);
        $draft = $id('Draft area id to add files to; omit to start a new draft area.');
        $filepath = $str('Folder inside the area, default "/". Example: "/week1/".', 255);
        $refresh = $bool('Build a new export even if the same one was already requested in the last 24 hours '
            . '(by default the existing one is returned).');
        $ttl = ['type' => 'integer', 'minimum' => 60, 'maximum' => 86400,
            'description' => 'Link lifetime in seconds (cannot exceed the site maximum).'];

        return [
            'file_list' => [
                'title' => 'List files',
                'description' => 'Browse Moodle files the signed-in user can see. With no arguments it lists your own areas '
                    . '(private files, backups) plus your upload limits. Pass courseid (recursive=true walks the course files '
                    . 'and every activity you can see), cmid, contextid, userid, or draftitemid (an upload draft area). Narrow '
                    . 'to one area with component+filearea, optionally itemid (usually 0) and filepath, e.g. component=user '
                    . 'filearea=private itemid=0; component=user filearea=draft alone lists your draft areas. '
                    . 'Entries have type context|area|folder|file; files carry uri '
                    . '(moodle://file/...), size, mimetype, author, license and writable. Use a file uri with file_read, '
                    . 'file_get_download_url or file_delete; list a folder by passing its contextid/component/filearea/itemid/'
                    . 'filepath. Results are paged: limit (max 1000) and offset; follow nextoffset while hasmore is true.',
                'properties' => [
                    'courseid' => $id('Course id.'),
                    'cmid' => $id('Course module (activity) id.'),
                    'contextid' => $id('Any context id.'),
                    'userid' => $id('User id (only your own user files are visible).'),
                    'draftitemid' => $id('List one of your draft upload areas.'),
                    'component' => $str('Frankenstyle component, e.g. user, course, mod_resource, mod_folder, backup.', 100),
                    'filearea' => $str('File area, e.g. private, content, intro, legacy, course.', 100),
                    'itemid' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Item id within the area (usually 0).'],
                    'filepath' => $filepath,
                    'recursive' => $bool('Include sub-folders and, for a course, all activities. Default false.'),
                    'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Max depth when recursive.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'Page size, default 200.'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Entries to skip.'],
                ],
                'required' => [],
            ],
            'file_read' => [
                'title' => 'Read a file',
                'description' => 'Read a file you can access, by uri (moodle://file/... from file_list) or url (any '
                    . 'pluginfile.php, webservice/pluginfile.php, tokenpluginfile.php or draftfile.php link on this '
                    . 'site). Word, PowerPoint (per slide, with speaker notes), Excel (per sheet, tab-separated), '
                    . 'OpenDocument, RTF, HTML and PDF files come back as their text; other text files as text. Text '
                    . 'is paged (256 KB per call by default): continue with the offset it reports. Images come back as '
                    . 'images you can see, audio as audio, other files up to 5 MB as base64. To SEE pages (slides, '
                    . 'diagrams, handwriting, scanned PDFs), use render="images" with pages="1-5" (max 10 pages per '
                    . 'call): PDF pages come back as images; Office files need a document converter on the site. '
                    . 'convert_to=pdf|txt uses the site\'s document converter when one is enabled.',
                'properties' => [
                    'uri' => $uri,
                    'url' => $url,
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Text: first byte to return.'],
                    'length' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Text: max bytes to return.'],
                    'render' => ['type' => 'string', 'enum' => ['images'],
                        'description' => 'Return document pages as images instead of text.'],
                    'pages' => $str('With render: a page or range such as "3" or "1-5" (max 10). Default "1-5".', 20),
                    'convert_to' => ['type' => 'string', 'enum' => ['pdf', 'txt'],
                        'description' => 'Convert with the site document converter before reading.'],
                ],
                'required' => [],
                'oneof' => [['uri', 'url']],
            ],
            'file_get_download_url' => [
                'title' => 'Get a download link',
                'description' => 'Get a short-lived signed link (15 minutes by default) to download a file you can access, of any '
                    . 'size, with no other credentials, e.g. curl -fL -o out.pdf "<url>". The link supports HTTP Range so '
                    . 'large downloads can resume (curl -C -), and stops working when it expires or the connector is revoked. '
                    . 'Identify the file by uri or url. preview=thumb|bigthumb returns an image thumbnail; '
                    . 'forcedownload=false serves the file inline instead of as an attachment.',
                'properties' => [
                    'uri' => $uri,
                    'url' => $url,
                    'forcedownload' => $bool('Send as attachment (default true).'),
                    'preview' => ['type' => 'string', 'enum' => ['thumb', 'bigthumb', 'tinyicon'],
                        'description' => 'Thumbnail instead of the file.'],
                    'ttl' => $ttl,
                ],
                'required' => [],
                'oneof' => [['uri', 'url']],
            ],
            'file_create_upload_url' => [
                'title' => 'Get an upload link',
                'description' => 'Get a short-lived signed URL (1 hour by default) for uploading files of any size from disk into '
                    . 'your Moodle draft area. Raw body (best for big files): curl -T ./file.pdf "<url>&filename=file.pdf". '
                    . 'Multipart, several files at once: curl -F "file=@./a.pdf" -F "file2=@./b.png" "<url>". Very large '
                    . 'files can be sent in chunks with the header Content-Range: bytes start-end/total; after a failure, '
                    . 'resume from the byte the server reports (a new link for the same draftitemid and filename '
                    . 'continues the upload). The JSON response lists the stored files with their uri. '
                    . 'Same-named files are auto-renamed unless overwrite=true. Pass the returned draftitemid to file_save_draft '
                    . 'or to any Moodle function that accepts draft files (see file_save_draft). Pass courseid/cmid/'
                    . 'contextid of where the file is going to apply that place\'s upload size limit.',
                'properties' => [
                    'draftitemid' => $draft,
                    'filepath' => $filepath,
                    'filename' => $str('Fixed file name for raw uploads (otherwise use &filename= or Content-Disposition).', 255),
                    'overwrite' => $bool('Replace same-named files in the draft area instead of renaming.'),
                    'courseid' => $id('Course the upload is for (size limit).'),
                    'cmid' => $id('Activity the upload is for (size limit).'),
                    'contextid' => $id('Context the upload is for (size limit).'),
                    'ttl' => $ttl,
                ],
                'required' => [],
            ],
            'file_upload' => [
                'title' => 'Upload a small file',
                'description' => 'Upload a small file (15 MB by default) given inline as base64 (content_base64) or plain text '
                    . '(content_text) into your draft area. Returns draftitemid and the file uri. Reuse draftitemid to put '
                    . 'several files in one draft area. Then pass draftitemid to file_save_draft or to a Moodle function that '
                    . 'accepts draft files (submissions, forum attachments, resources; see file_save_draft). For larger files '
                    . 'use file_create_upload_url.',
                'properties' => [
                    'filename' => $str('File name including extension.', 255),
                    'content_base64' => ['type' => 'string', 'description' => 'File content, base64 encoded.'],
                    'content_text' => ['type' => 'string', 'description' => 'File content as UTF-8 text.'],
                    'draftitemid' => $draft,
                    'filepath' => $filepath,
                    'overwrite' => $bool('Replace a same-named file instead of renaming the new one.'),
                ],
                'required' => ['filename'],
                'oneof' => [['content_base64', 'content_text']],
            ],
            'file_upload_from_url' => [
                'title' => 'Upload from a URL',
                'description' => 'Fetch a public http(s) URL on the server straight into your draft area, subject to the site\'s '
                    . 'URL security rules and your upload size limit. Returns draftitemid and the file uri for file_save_draft '
                    . 'or Moodle functions that accept draft files.',
                'properties' => [
                    'url' => $str('Public http(s) URL.', 4000),
                    'filename' => $str('File name to store (default: from the response or URL).', 255),
                    'draftitemid' => $draft,
                    'filepath' => $filepath,
                    'overwrite' => $bool('Replace a same-named file instead of renaming the new one.'),
                ],
                'required' => ['url'],
            ],
            'file_save_draft' => [
                'title' => 'Save draft files to an area',
                'description' => 'Copy a draft area (draftitemid from file_upload or file_create_upload_url) into a file '
                    . 'area you may '
                    . 'write to: your private files (the default), course files, a folder activity (cmid + component=mod_folder '
                    . 'filearea=content), backup areas... mode=merge (default) adds files and updates same-named ones; '
                    . 'mode=replace makes the area match the draft exactly and DELETES its other files. Most attachments are '
                    . 'instead saved by giving the draftitemid to the Moodle function: mod_assign_save_submission '
                    . '(plugindata.files_filemanager), mod_assign_save_grade (feedback plugindata.files_filemanager), '
                    . 'mod_forum_add_discussion and mod_forum_add_discussion_post (options attachmentsid, inlineattachmentsid), '
                    . 'mod_glossary_add_entry and mod_workshop_add_submission (attachmentsid), mod_data_add_entry (file field '
                    . 'value), core_user_update_picture (draftitemid), core_user_add_user_private_files (draftid), '
                    . 'wrapper_course_add_module (options files for resource/folder, introeditor itemid, packagefile for '
                    . 'scorm/h5p).',
                'properties' => [
                    'draftitemid' => $id('Draft area to save.'),
                    'courseid' => $id('Target course.'),
                    'cmid' => $id('Target activity.'),
                    'contextid' => $id('Target context.'),
                    'component' => $str('Target component, default user.', 100),
                    'filearea' => $str('Target file area, default private.', 100),
                    'itemid' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Target item id, default 0.'],
                    'mode' => ['type' => 'string', 'enum' => ['merge', 'replace'], 'description' => 'Default merge.'],
                ],
                'required' => ['draftitemid'],
            ],
            'file_delete' => [
                'title' => 'Delete a file',
                'description' => 'Delete a file or an empty folder by uri, where Moodle lets you manage files (your private '
                    . 'files and '
                    . 'drafts, course or folder files you manage, backup files). Submissions, posts and similar must be '
                    . 'changed through their own Moodle functions.',
                'properties' => ['uri' => $uri],
                'required' => ['uri'],
            ],
            'file_set_course_image' => [
                'title' => 'Set course image',
                'description' => 'Set the course image shown in course listings from a draft area holding the image (upload it '
                    . 'first with file_upload or file_create_upload_url). Replaces the current image. Requires permission to '
                    . 'edit the course settings.',
                'properties' => ['courseid' => $id('Course id.'), 'draftitemid' => $id('Draft area with the image.')],
                'required' => ['courseid', 'draftitemid'],
            ],
            'export_course_content' => [
                'title' => 'Export course content',
                'description' => 'Build a zip of the course content you can see (Moodle\'s "Download course content"), when the '
                    . 'site and course allow it. The zip is built in the background: poll backup_status with the returned '
                    . 'backupid (export...) until it is finished, then download it with the returned link, e.g. '
                    . 'curl -fL -o course.zip "<url>". Asking again returns the same export (refresh=true builds a '
                    . 'new one); exports are deleted after 24 hours.',
                'properties' => ['courseid' => $id('Course id.'), 'refresh' => $refresh],
                'required' => ['courseid'],
            ],
            'export_assignment_submissions' => [
                'title' => 'Download all assignment submissions',
                'description' => 'Build a zip of all submission files of an assignment (graders only), optionally limited to '
                    . 'one group, as Moodle\'s "Download all submissions". The zip is built in the background: poll '
                    . 'backup_status with the returned backupid (export...), then download it with the returned link. '
                    . 'Asking again returns the same export (refresh=true builds a new one); exports are deleted after 24 '
                    . 'hours.',
                'properties' => ['cmid' => $id('Assignment course module id.'), 'groupid' => $id('Optional group id.'),
                    'refresh' => $refresh],
                'required' => ['cmid'],
            ],
            'backup_create' => [
                'title' => 'Create a backup',
                'description' => 'Start a Moodle backup (.mbz) of a course, section or activity. It runs in the background; poll '
                    . 'backup_status with the returned backupid, then download the file or restore it with restore_from_draft. '
                    . 'include_users needs permission to back up user data; anonymize hides user identities.',
                'properties' => [
                    'courseid' => $id('Back up a whole course.'),
                    'sectionid' => $id('Back up one section (course_sections id).'),
                    'cmid' => $id('Back up one activity.'),
                    'include_users' => $bool('Include enrolments and user data. Default false.'),
                    'anonymize' => $bool('Anonymise user data. Default false.'),
                ],
                'required' => [],
                'oneof' => [['courseid', 'sectionid', 'cmid']],
            ],
            'backup_status' => [
                'title' => 'Backup, restore or export status',
                'description' => 'State and progress of a backup, restore or export you started. A finished backup returns '
                    . 'the .mbz uri and a download link; a finished export returns the zip uri and a download link; a '
                    . 'finished restore returns the course id. A failed one returns the reason.',
                'properties' => ['backupid' => $str('backupid from backup_create, restoreid from restore_from_draft, or the '
                    . 'backupid (export...) from export_course_content / export_assignment_submissions.', 64)],
                'required' => ['backupid'],
            ],
            'restore_from_draft' => [
                'title' => 'Restore a backup',
                'description' => 'Restore a Moodle backup (.mbz) from your draft area (draftitemid, plus filename if the '
                    . 'area holds '
                    . 'several) or from an existing backup file uri (e.g. from backup_status). target=new_course needs categoryid '
                    . '(optional fullname, shortname); existing_add adds the content to courseid; existing_delete first DELETES '
                    . 'the current content of courseid. Runs in the background: poll backup_status with the returned restoreid.',
                'properties' => [
                    'draftitemid' => $id('Draft area holding the .mbz.'),
                    'filename' => $str('The .mbz file name in the draft area.', 255),
                    'uri' => $uri,
                    'target' => ['type' => 'string', 'enum' => ['new_course', 'existing_add', 'existing_delete']],
                    'courseid' => $id('Existing target course.'),
                    'categoryid' => $id('Category for a new course.'),
                    'fullname' => $str('New course full name.', 254),
                    'shortname' => $str('New course short name.', 255),
                    'include_users' => $bool('Restore user data contained in the backup. Default false.'),
                ],
                'required' => ['target'],
                'oneof' => [['draftitemid', 'uri']],
            ],
        ];
    }
}
