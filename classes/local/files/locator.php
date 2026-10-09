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

use context;
use core_external\restricted_context_exception;
use file_info;
use moodle_exception;
use stored_file;

/**
 * Addressing for Moodle files: moodle://file URIs, on-site file URLs, file_browser lookups and context restriction.
 *
 * Every lookup goes through file_browser, which applies Moodle's own read/write permission rules per area.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class locator {
    /** URI prefix for files. */
    public const PREFIX = 'moodle://file/';

    /** Context level names for listings. */
    private const LEVELS = [CONTEXT_SYSTEM => 'system', CONTEXT_USER => 'user', CONTEXT_COURSECAT => 'category',
        CONTEXT_COURSE => 'course', CONTEXT_MODULE => 'module', CONTEXT_BLOCK => 'block'];

    /** Site scripts that serve files, mapped to whether their first path segment is a token. */
    private const FILE_SCRIPTS = [
        '/webservice/pluginfile.php' => false,
        '/tokenpluginfile.php' => true,
        '/pluginfile.php' => false,
        '/draftfile.php' => false,
    ];

    /**
     * Build a moodle://file URI. Directories end with a slash.
     *
     * @param int $contextid Context id.
     * @param string $component Component.
     * @param string $filearea File area.
     * @param int $itemid Item id.
     * @param string $filepath File path, starting and ending with '/'.
     * @param string $filename File name, or '.' for a directory.
     * @return string
     */
    public static function uri(
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
        string $filepath,
        string $filename
    ): string {
        $segments = [(string)$contextid, $component, $filearea, (string)$itemid];
        foreach (explode('/', trim($filepath, '/')) as $segment) {
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }
        $uri = self::PREFIX . implode('/', array_map('rawurlencode', $segments)) . '/';
        return $filename === '.' ? $uri : $uri . rawurlencode($filename);
    }

    /**
     * URI of a stored file.
     *
     * @param stored_file $file File.
     * @return string
     */
    public static function uri_for(stored_file $file): string {
        return self::uri(
            (int)$file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            (int)$file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    /**
     * Parse a moodle://file URI.
     *
     * @param string $uri URI.
     * @return array{contextid:int, component:string, filearea:string, itemid:int, filepath:string, filename:string}
     * @throws moodle_exception
     */
    public static function parse_uri(string $uri): array {
        if (strpos($uri, self::PREFIX) !== 0) {
            throw new moodle_exception('invalidparameter', 'debug', '', null, 'Expected a moodle://file/ URI.');
        }
        $rest = substr($uri, strlen(self::PREFIX));
        $isdir = $rest === '' || substr($rest, -1) === '/';
        $segments = array_map('rawurldecode', array_values(array_filter(explode('/', $rest), 'strlen')));
        $params = self::params_from_segments($segments, true, $isdir);
        if ($params === null) {
            throw new moodle_exception(
                'invalidparameter',
                'debug',
                '',
                null,
                'Malformed file URI; expected moodle://file/{contextid}/{component}/{filearea}/{itemid}/{path}{filename}.'
            );
        }
        return $params;
    }

    /**
     * Parse an on-site file URL (pluginfile.php, webservice/pluginfile.php, tokenpluginfile.php, draftfile.php).
     *
     * @param string $url URL.
     * @return array{relativepath:string, draft:bool}
     * @throws moodle_exception
     */
    public static function parse_site_url(string $url): array {
        global $CFG;

        $strip = static fn(string $u): string => preg_replace('#^https?://#i', '', $u);
        $root = rtrim($strip($CFG->wwwroot), '/');
        $target = $strip(trim($url));
        if (strpos($target, $root . '/') !== 0) {
            throw new moodle_exception('invalidparameter', 'debug', '', null, 'The URL is not a file URL on this Moodle site.');
        }
        $parts = explode('?', substr($target, strlen($root)), 2);
        $path = $parts[0];
        parse_str($parts[1] ?? '', $query);

        foreach (self::FILE_SCRIPTS as $script => $tokenfirst) {
            if ($path !== $script && strpos($path, $script . '/') !== 0) {
                continue;
            }
            $relative = substr($path, strlen($script));
            if ($relative === '' && isset($query['file']) && is_string($query['file'])) {
                $relative = $query['file'];
            }
            $segments = array_map('rawurldecode', array_values(array_filter(explode('/', $relative), 'strlen')));
            if ($tokenfirst) {
                array_shift($segments);
            }
            $relativepath = clean_param('/' . implode('/', $segments), PARAM_PATH);
            if (count($segments) < 4 || $relativepath === '') {
                break;
            }
            return ['relativepath' => $relativepath, 'draft' => $script === '/draftfile.php'];
        }
        throw new moodle_exception('invalidparameter', 'debug', '', null, 'Unrecognised file URL.');
    }

    /**
     * Interpret a pluginfile-style relative path as file params.
     *
     * @param string $relativepath Path starting with '/{contextid}/{component}/{filearea}/'.
     * @param bool $withitemid Whether the segment after the file area is an item id.
     * @return array|null Params, or null when the path cannot be interpreted that way.
     */
    public static function params_from_relativepath(string $relativepath, bool $withitemid): ?array {
        $segments = array_values(array_filter(explode('/', $relativepath), 'strlen'));
        return self::params_from_segments($segments, $withitemid, false);
    }

    /**
     * Pluginfile-style relative path for file params.
     *
     * @param array $params File params.
     * @param bool $withitemid Whether to include the item id segment.
     * @return string
     */
    public static function relativepath(array $params, bool $withitemid = true): string {
        $path = '/' . $params['contextid'] . '/' . $params['component'] . '/' . $params['filearea'];
        if ($withitemid) {
            $path .= '/' . $params['itemid'];
        }
        return $path . $params['filepath'] . ($params['filename'] === '.' ? '' : $params['filename']);
    }

    /**
     * Look a file or directory up through file_browser, which applies Moodle's access rules.
     *
     * @param array $params contextid plus optional component, filearea, itemid, filepath, filename.
     * @return file_info|null Null when missing or not accessible.
     */
    public static function file_info(array $params): ?file_info {
        $context = context::instance_by_id((int)$params['contextid'], IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        return get_file_browser()->get_file_info(
            $context,
            $params['component'] ?? null,
            $params['filearea'] ?? null,
            $params['itemid'] ?? null,
            $params['filepath'] ?? null,
            $params['filename'] ?? null
        );
    }

    /**
     * The stored file behind a readable, non-directory file_info.
     *
     * @param file_info $info File info.
     * @return stored_file|null
     */
    public static function stored_file(file_info $info): ?stored_file {
        $p = $info->get_params();
        if (!$info->is_readable() || $info->is_directory() || $p['component'] === null || $p['itemid'] === null) {
            return null;
        }
        return get_file_storage()->get_file(
            $p['contextid'],
            $p['component'],
            $p['filearea'],
            $p['itemid'],
            $p['filepath'],
            $p['filename']
        ) ?: null;
    }

    /**
     * Throw unless a context is inside the token's context restriction. The caller's own draft area is exempt
     * because drafts are per-user scratch space needed to submit files anywhere, and so is their own export
     * area, whose zips were built from contexts checked against the restriction when the export was queued.
     *
     * @param context $context Target context.
     * @param context|null $restriction Token context restriction.
     * @param string|null $component Component, for the draft and export exemptions.
     * @param string|null $filearea File area, for the draft and export exemptions.
     * @return void
     * @throws restricted_context_exception
     */
    public static function check_restriction(
        context $context,
        ?context $restriction,
        ?string $component = null,
        ?string $filearea = null
    ): void {
        global $USER;

        if ($restriction === null || $restriction->contextlevel == CONTEXT_SYSTEM || $restriction->id == $context->id) {
            return;
        }
        $ownarea = ($component === 'user' && $filearea === 'draft')
            || ($component === export_service::COMPONENT && $filearea === export_service::AREA);
        if ($ownarea && $context->contextlevel == CONTEXT_USER && (int)$context->instanceid === (int)$USER->id) {
            return;
        }
        if (!in_array($restriction->id, $context->get_parent_context_ids())) {
            throw new restricted_context_exception();
        }
    }

    /**
     * Describe a file_info node as a listing entry.
     *
     * @param file_info $info Node.
     * @return array
     */
    public static function describe(file_info $info): array {
        $p = $info->get_params();
        $entry = ['name' => (string)$info->get_visible_name(), 'contextid' => (int)$p['contextid']];

        if ($p['component'] === null) {
            $context = context::instance_by_id((int)$p['contextid']);
            $entry['type'] = 'context';
            $entry['contextlevel'] = self::LEVELS[$context->contextlevel] ?? (string)$context->contextlevel;
            $entry['instanceid'] = (int)$context->instanceid;
            if ($context->contextlevel == CONTEXT_MODULE) {
                $entry['cmid'] = (int)$context->instanceid;
            }
            return $entry;
        }

        $entry['component'] = $p['component'];
        $entry['filearea'] = $p['filearea'];
        if ($p['itemid'] === null || $p['filepath'] === null) {
            $entry['type'] = 'area';
            return $entry;
        }

        $filename = $p['filename'] ?? '.';
        $isdir = $info->is_directory();
        $entry += [
            'type' => $isdir ? 'folder' : 'file',
            'itemid' => (int)$p['itemid'],
            'filepath' => $p['filepath'],
            'filename' => $filename,
            'uri' => self::uri(
                (int)$p['contextid'],
                $p['component'],
                $p['filearea'],
                (int)$p['itemid'],
                $p['filepath'],
                $isdir ? '.' : $filename
            ),
            'timemodified' => (int)$info->get_timemodified(),
        ];
        if (!$isdir) {
            $entry += [
                'size' => (int)$info->get_filesize(),
                'mimetype' => (string)$info->get_mimetype(),
                'author' => (string)$info->get_author(),
                'license' => (string)$info->get_license(),
            ];
        }
        $entry['writable'] = (bool)$info->is_writable();
        return $entry;
    }

    /**
     * Build params from path segments.
     *
     * @param array $segments Decoded segments: contextid, component, filearea, [itemid], path..., [filename].
     * @param bool $withitemid Whether segment 4 is the item id.
     * @param bool $isdir Whether the last segment is a directory rather than a file name.
     * @return array|null
     */
    private static function params_from_segments(array $segments, bool $withitemid, bool $isdir): ?array {
        $min = $withitemid ? 4 : 3;
        if (count($segments) < $min || !ctype_digit((string)$segments[0]) || ($withitemid && !ctype_digit((string)$segments[3]))) {
            return null;
        }
        $component = clean_param($segments[1], PARAM_COMPONENT);
        $filearea = clean_param($segments[2], PARAM_AREA);
        $rest = array_slice($segments, $min);
        $filename = $isdir || !$rest ? '.' : array_pop($rest);
        foreach ($filename === '.' ? $rest : array_merge($rest, [$filename]) as $segment) {
            if (clean_param($segment, PARAM_FILE) !== $segment) {
                return null;
            }
        }
        if ($component === '' || $filearea === '' || $component !== $segments[1] || $filearea !== $segments[2]) {
            return null;
        }
        return [
            'contextid' => (int)$segments[0],
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $withitemid ? (int)$segments[3] : 0,
            'filepath' => $rest ? '/' . implode('/', $rest) . '/' : '/',
            'filename' => $filename,
        ];
    }
}
