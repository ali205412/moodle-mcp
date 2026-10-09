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

use context_user;
use file_info;
use moodle_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * file_list: browse file areas through file_browser, so listings show only what the user may see.
 *
 * Course contexts include their visible activities as children, so a recursive listing of a course
 * covers course files and every activity's files.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lister {
    /** Default and maximum page sizes. */
    private const DEFAULT_LIMIT = 200;

    /** Maximum page size. */
    private const MAX_LIMIT = 1000;

    /** Maximum recursion depth. */
    private const MAX_DEPTH = 10;

    /** Stop walking after visiting this many nodes. */
    private const MAX_VISITED = 5000;

    /** @var array Collected entries. */
    private array $entries = [];

    /** @var int Nodes visited. */
    private int $visited = 0;

    /** @var bool Whether more entries exist beyond the page. */
    private bool $hasmore = false;

    /**
     * List a context, area or folder.
     *
     * @param array $args contextid|courseid|cmid|userid|draftitemid, component, filearea, itemid, filepath, recursive,
     *                    depth, limit, offset.
     * @param call_context $ctx Request context.
     * @return array
     */
    public function list(array $args, call_context $ctx): array {
        global $USER;

        $limit = min(max(1, (int)($args['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = max(0, (int)($args['offset'] ?? 0));
        $recursive = !empty($args['recursive']);
        $depth = $recursive ? min(max(1, (int)($args['depth'] ?? self::MAX_DEPTH)), self::MAX_DEPTH) : 1;
        $filepath = isset($args['filepath']) ? file_correct_filepath(clean_param((string)$args['filepath'], PARAM_PATH)) : null;

        if (!empty($args['draftitemid'])) {
            $context = context_user::instance($USER->id);
            $params = ['contextid' => $context->id, 'component' => 'user', 'filearea' => 'draft',
                'itemid' => (int)$args['draftitemid'], 'filepath' => $filepath ?? '/', 'filename' => '.'];
        } else {
            $context = file_service::target_context($args) ?? context_user::instance($USER->id);
            $params = ['contextid' => $context->id];
            if (!empty($args['component']) && !empty($args['filearea'])) {
                $params += ['component' => clean_param((string)$args['component'], PARAM_COMPONENT),
                    'filearea' => clean_param((string)$args['filearea'], PARAM_AREA)];
                if (isset($args['itemid']) || $filepath !== null) {
                    $params += ['itemid' => (int)($args['itemid'] ?? 0), 'filepath' => $filepath ?? '/', 'filename' => '.'];
                }
            }
        }
        locator::check_restriction($context, $ctx->restrictedcontext, $params['component'] ?? null, $params['filearea'] ?? null);

        $node = locator::file_info($params);
        if (
            $node !== null && isset($params['component']) && !isset($params['itemid'])
                && $node->get_params()['component'] === null
        ) {
            // Areas without item ids (e.g. user private) resolve to their context when no item id is given.
            $node = locator::file_info($params + ['itemid' => 0, 'filepath' => '/', 'filename' => '.']);
        }
        if ($node === null || !$node->is_readable()) {
            throw new moodle_exception('filenotfound', 'error');
        }
        if (!$node->is_directory()) {
            return ['node' => locator::describe($node), 'entries' => [], 'hasmore' => false];
        }

        $courseactivities = $recursive && $context->contextlevel == CONTEXT_COURSE && !isset($params['component']);
        $this->walk($node, 1, $depth, $offset + $limit, $courseactivities);
        if ($courseactivities && !$this->hasmore) {
            $this->activity_files((int)$context->instanceid, $offset + $limit);
        }
        $result = [
            'node' => locator::describe($node),
            'entries' => array_slice($this->entries, $offset, $limit),
            'offset' => $offset,
            'hasmore' => $this->hasmore,
        ];
        if ($this->hasmore) {
            $result['nextoffset'] = $offset + $limit;
        }
        if ($context->contextlevel == CONTEXT_USER && (int)$context->instanceid === (int)$USER->id) {
            $result['limits'] = file_service::user_limits();
        }
        return $result;
    }

    /**
     * Depth-first walk collecting up to $want entries.
     *
     * @param file_info $node Folder-like node.
     * @param int $level Current depth (1 = direct children).
     * @param int $maxdepth Deepest level to descend to.
     * @param int $want Entries needed for the requested page.
     * @param bool $skipmodules Skip activity contexts (listed separately by activity_files()).
     * @return void
     */
    private function walk(file_info $node, int $level, int $maxdepth, int $want, bool $skipmodules = false): void {
        foreach ($node->get_children() as $child) {
            if (count($this->entries) >= $want || ++$this->visited > self::MAX_VISITED) {
                $this->hasmore = true;
                return;
            }
            $entry = locator::describe($child);
            if ($skipmodules && isset($entry['cmid'])) {
                continue;
            }
            $entry['depth'] = $level;
            $this->entries[] = $entry;
            if ($level < $maxdepth && $child->is_directory()) {
                $this->walk($child, $level + 1, $maxdepth, $want, $skipmodules);
                if ($this->hasmore) {
                    return;
                }
            }
        }
    }

    /**
     * Files of every activity the user can access, as core_course_get_contents reports them.
     *
     * file_browser hides some activity files from students (e.g. mod_resource without managefiles), while
     * the module's export_contents callback lists exactly what the user can open. Entries carry a url (and a
     * uri when derivable) that file_read and file_get_download_url accept; the download endpoint then lets
     * file_pluginfile() decide access.
     *
     * @param int $courseid Course id.
     * @param int $want Entries needed for the requested page.
     * @return void
     */
    private function activity_files(int $courseid, int $want): void {
        global $CFG;

        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $callback = $cm->modname . '_export_contents';
            require_once($CFG->dirroot . '/mod/' . $cm->modname . '/lib.php');
            if (!function_exists($callback)) {
                continue;
            }
            try {
                $contents = $callback($cm, 'webservice/pluginfile.php');
            } catch (\moodle_exception $e) {
                debugging("{$callback} failed for cm {$cm->id}: " . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            foreach ($contents as $content) {
                if (($content['type'] ?? '') !== 'file' || empty($content['fileurl'])) {
                    continue;
                }
                if (count($this->entries) >= $want) {
                    $this->hasmore = true;
                    return;
                }
                $url = preg_replace('/[?&]forcedownload=1$/', '', (string)$content['fileurl']);
                $entry = ['name' => (string)$content['filename'], 'type' => 'file', 'cmid' => (int)$cm->id,
                    'activity' => format_string($cm->name, true, ['context' => $cm->context]), 'modname' => $cm->modname,
                    'filepath' => (string)($content['filepath'] ?? '/'), 'filename' => (string)$content['filename'],
                    'size' => (int)($content['filesize'] ?? 0), 'mimetype' => (string)($content['mimetype'] ?? ''),
                    'timemodified' => (int)($content['timemodified'] ?? 0), 'author' => (string)($content['author'] ?? ''),
                    'license' => (string)($content['license'] ?? ''), 'url' => $url, 'depth' => 1];
                try {
                    $params = locator::params_from_relativepath(locator::parse_site_url($url)['relativepath'], true);
                } catch (\moodle_exception $e) {
                    $params = null;
                }
                if ($params !== null) {
                    $entry['uri'] = locator::uri(
                        $params['contextid'],
                        $params['component'],
                        $params['filearea'],
                        $params['itemid'],
                        $params['filepath'],
                        $params['filename']
                    );
                }
                $this->entries[] = $entry;
            }
        }
    }
}
