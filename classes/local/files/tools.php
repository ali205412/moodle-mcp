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

use moodle_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * MCP file, export, backup and restore tools.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tools {
    /** Tools that change Moodle state (need write scope). */
    private const MUTATING = ['file_create_upload_url', 'file_upload', 'file_upload_from_url', 'file_save_draft', 'file_delete',
        'file_set_course_image', 'backup_create', 'restore_from_draft'];

    /** Tools hidden when the connector service disallows downloads. */
    private const NEEDS_DOWNLOAD = ['file_read', 'file_get_download_url', 'export_course_content', 'export_assignment_submissions'];

    /** Tools hidden when the connector service disallows uploads. */
    private const NEEDS_UPLOAD = ['file_create_upload_url', 'file_upload', 'file_upload_from_url'];

    /** Tools kept loaded by clients that defer tool definitions. */
    private const ALWAYS_LOAD = ['file_list', 'file_read', 'file_get_download_url', 'file_create_upload_url', 'file_upload'];

    /**
     * Wire-shape tool definitions the user could plausibly use.
     *
     * @param call_context $ctx Request context.
     * @return array
     */
    public static function describe(call_context $ctx): array {
        global $DB;

        if ($ctx->user === null || isguestuser($ctx->user)) {
            return [];
        }
        $service = $ctx->serviceid
            ? $DB->get_record('external_services', ['id' => $ctx->serviceid], 'id, downloadfiles, uploadfiles') : null;
        $tools = [];
        foreach (self::definitions() as $name => $def) {
            if (
                $service && ((empty($service->downloadfiles) && in_array($name, self::NEEDS_DOWNLOAD, true))
                    || (empty($service->uploadfiles) && in_array($name, self::NEEDS_UPLOAD, true)))
            ) {
                continue;
            }
            $tool = [
                'name' => $name,
                'title' => $def['title'],
                'description' => $def['description'],
                'inputSchema' => ['type' => 'object', 'properties' => $def['properties'] ?: (object)[],
                    'additionalProperties' => false] + ($def['required'] ? ['required' => $def['required']] : []),
                'annotations' => [
                    'readOnlyHint' => !self::is_mutating($name),
                    'destructiveHint' => in_array($name, ['file_delete', 'file_save_draft', 'restore_from_draft'], true),
                    'idempotentHint' => !in_array($name, ['file_upload', 'file_upload_from_url', 'backup_create',
                        'restore_from_draft', 'file_create_upload_url', 'export_course_content',
                        'export_assignment_submissions'], true),
                    'openWorldHint' => $name === 'file_upload_from_url',
                ],
            ];
            $meta = [];
            if (in_array($name, self::ALWAYS_LOAD, true)) {
                $meta['anthropic/alwaysLoad'] = true;
            }
            if ($name === 'file_read') {
                $meta['anthropic/maxResultSizeChars'] = 500000;
            }
            if ($meta) {
                $tool['_meta'] = $meta;
            }
            $tools[] = $tool;
        }
        return $tools;
    }

    /**
     * Whether a tool name belongs to this class.
     *
     * @param string $name Tool name.
     * @return bool
     */
    public static function handles(string $name): bool {
        return array_key_exists($name, self::definitions());
    }

    /**
     * Whether a tool changes state.
     *
     * @param string $name Tool name.
     * @return bool
     */
    public static function is_mutating(string $name): bool {
        return in_array($name, self::MUTATING, true);
    }

    /**
     * Run a tool and return a CallToolResult. Failures are thrown for the dispatcher to convert.
     *
     * @param string $name Tool name.
     * @param array $args Arguments.
     * @param call_context $ctx Request context.
     * @return array
     */
    public static function execute(string $name, array $args, call_context $ctx): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $definitions = self::definitions();
        if (!isset($definitions[$name])) {
            throw new moodle_exception('invalidparameter', 'debug', '', null, 'Unknown tool: ' . $name);
        }
        $args = self::validate($definitions[$name], $args);
        file_service::apply_restriction($ctx);
        $files = new file_service();

        if ($name === 'file_read') {
            return (new file_reader($ctx))->read_tool($args);
        }
        $payload = match ($name) {
            'file_list' => (new lister())->list($args, $ctx),
            'file_get_download_url' => self::download_url($args, $ctx),
            'file_create_upload_url' => self::upload_url($args, $ctx),
            'file_upload' => $files->upload_inline($args, $ctx),
            'file_upload_from_url' => $files->upload_from_url($args, $ctx),
            'file_save_draft' => $files->save_draft($args, $ctx),
            'file_delete' => $files->delete($args, $ctx),
            'file_set_course_image' => $files->set_course_image($args, $ctx),
            'export_course_content' => (new export_service())->course_content($args, $ctx),
            'export_assignment_submissions' => (new export_service())->assign_submissions($args, $ctx),
            'backup_create' => (new backup_service())->create($args, $ctx),
            'backup_status' => (new backup_service())->status($args, $ctx),
            'restore_from_draft' => (new backup_service())->restore($args, $ctx),
        };
        return [
            'content' => [['type' => 'text', 'text' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            )]],
            'structuredContent' => $payload,
        ];
    }

    /**
     * file_get_download_url.
     *
     * @param array $args uri|url, forcedownload, preview, ttl.
     * @param call_context $ctx Request context.
     * @return array
     */
    private static function download_url(array $args, call_context $ctx): array {
        $reader = new file_reader($ctx);
        $target = $reader->resolve($args['uri'] ?? null, $args['url'] ?? null);
        $link = tickets::download_url($ctx, file_reader::download_claims(
            $target,
            $args['forcedownload'] ?? true,
            $args['preview'] ?? null
        ), $args['ttl'] ?? null);
        $result = ['url' => $link['url'], 'expires' => $link['expires']];
        $filename = basename($target['relativepath']);
        if ($target['info'] !== null) {
            $result += ['filename' => (string)$target['info']->get_visible_name(), 'size' => (int)$target['info']->get_filesize(),
                'mimetype' => (string)$target['info']->get_mimetype()];
            $filename = $result['filename'];
        }
        $result['curl'] = 'curl -fL -o ' . escapeshellarg($filename) . ' ' . escapeshellarg($link['url']);
        return $result;
    }

    /**
     * file_create_upload_url.
     *
     * @param array $args draftitemid, filepath, filename, overwrite, contextid|courseid|cmid, ttl.
     * @param call_context $ctx Request context.
     * @return array
     */
    private static function upload_url(array $args, call_context $ctx): array {
        global $USER;

        if (isguestuser()) {
            throw new moodle_exception('noguest');
        }
        $draftitemid = (int)($args['draftitemid'] ?? 0) ?: file_get_unused_draft_itemid();
        $context = file_service::target_context($args) ?? \context_user::instance($USER->id);
        locator::check_restriction($context, $ctx->restrictedcontext, 'user', 'draft');
        $maxbytes = limits::max_upload_bytes($context);
        $filename = isset($args['filename']) ? clean_param($args['filename'], PARAM_FILE) : null;
        $link = tickets::upload_url($ctx, [
            'd' => $draftitemid,
            'fp' => file_correct_filepath(clean_param($args['filepath'] ?? '/', PARAM_PATH)),
            'fn' => $filename ?: null,
            'mb' => $maxbytes,
            'ow' => empty($args['overwrite']) ? 0 : 1,
        ], $args['ttl'] ?? null);
        $named = $filename ? $link['url'] : $link['url'] . '&filename=file.pdf';
        return [
            'draftitemid' => $draftitemid,
            'url' => $link['url'],
            'expires' => $link['expires'],
            'maxbytes' => $maxbytes,
            'curl' => 'curl -fS -T ./file.pdf ' . escapeshellarg($named),
            'curlmultipart' => 'curl -fS -F "file=@./a.pdf" -F "file2=@./b.png" ' . escapeshellarg($link['url']),
            'curlchunk' => 'curl -fS -T ./part1 -H "Content-Range: bytes 0-104857599/<total>" ' . escapeshellarg($named),
            'next' => 'After uploading, pass draftitemid to file_save_draft or to a Moodle function that accepts draft files.',
        ];
    }

    /**
     * Validate and normalise arguments against a tool's schema.
     *
     * @param array $def Tool definition.
     * @param array $args Raw arguments.
     * @return array
     * @throws moodle_exception
     */
    private static function validate(array $def, array $args): array {
        $clean = [];
        foreach ($args as $key => $value) {
            $prop = $def['properties'][$key] ?? null;
            if ($prop === null) {
                throw self::invalid("Unknown argument '{$key}'.");
            }
            if ($value === null) {
                continue;
            }
            switch ($prop['type']) {
                case 'integer':
                    if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                        $value = (int)$value;
                    }
                    if (
                        !is_int($value) || $value < ($prop['minimum'] ?? PHP_INT_MIN)
                            || $value > ($prop['maximum'] ?? PHP_INT_MAX)
                    ) {
                        throw self::invalid("'{$key}' must be an integer in range.");
                    }
                    break;
                case 'boolean':
                    if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                        throw self::invalid("'{$key}' must be a boolean.");
                    }
                    $value = is_bool($value) ? $value : in_array($value, [1, '1', 'true'], true);
                    break;
                default:
                    if (
                        !is_string($value) || strlen($value) > ($prop['maxLength'] ?? PHP_INT_MAX)
                            || (isset($prop['enum']) && !in_array($value, $prop['enum'], true))
                    ) {
                        throw self::invalid("'{$key}' must be " . (isset($prop['enum'])
                            ? 'one of ' . implode(', ', $prop['enum']) : 'a string') . '.');
                    }
            }
            $clean[$key] = $value;
        }
        foreach ($def['required'] as $key) {
            if (!array_key_exists($key, $clean)) {
                throw self::invalid("'{$key}' is required.");
            }
        }
        foreach ($def['oneof'] ?? [] as $group) {
            if (count(array_intersect_key($clean, array_flip($group))) !== 1) {
                throw self::invalid('Provide exactly one of: ' . implode(', ', $group) . '.');
            }
        }
        return $clean;
    }

    /**
     * Invalid-argument error.
     *
     * @param string $message Message.
     * @return moodle_exception
     */
    private static function invalid(string $message): moodle_exception {
        return new moodle_exception('invalidparameter', 'debug', '', null, $message);
    }

    /**
     * Tool definitions: title, description, properties, required and oneof groups.
     *
     * @return array
     */
    private static function definitions(): array {
        static $definitions = null;
        if ($definitions === null) {
            $definitions = tool_definitions::all();
        }
        return $definitions;
    }
}
