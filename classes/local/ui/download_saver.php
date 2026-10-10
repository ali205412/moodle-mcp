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

namespace webservice_mcp\local\ui;

use webservice_mcp\local\files\file_service;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * Stores a non-HTML page response (CSV export, PDF report, zip) in the user's draft area.
 *
 * The file then works with file_read, file_get_download_url and every tool that takes a draft item id. Storage
 * goes through file_service::store_draft_file(), so size limits, antivirus and the draft-area rate limit apply.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_saver {
    /**
     * Whether a response should be saved as a file rather than parsed as a page.
     *
     * @param array $response session_bridge::fetch() result.
     * @return bool
     */
    public static function is_download(array $response): bool {
        $type = strtolower(trim(explode(';', (string)($response['contenttype'] ?? ''))[0]));
        return !empty($response['filename']) || !in_array($type, ['text/html', 'application/xhtml+xml', ''], true);
    }

    /**
     * Save a response body as a new draft file.
     *
     * @param array $response body, contenttype, url and (from Content-Disposition) filename.
     * @param call_context $ctx Request context.
     * @return array uri, filename, mimetype, size, draftitemid.
     */
    public static function save(array $response, call_context $ctx): array {
        $body = (string)($response['body'] ?? '');
        if ($body === '') {
            throw new transfer_exception(422, 'emptydownload', 'The page returned an empty file.');
        }
        $filename = self::filename($response);
        $path = make_request_directory() . '/download';
        file_put_contents($path, $body);
        $stored = (new file_service())->store_draft_file($path, $filename, ['source' => (string)($response['url'] ?? '')], $ctx);
        return [
            'uri' => $stored['uri'],
            'filename' => $stored['filename'],
            'mimetype' => $stored['mimetype'],
            'size' => $stored['size'],
            'draftitemid' => $stored['draftitemid'],
        ];
    }

    /**
     * File name: Content-Disposition, else the URL's last segment, else "download"; with an extension matching the type.
     *
     * @param array $response Response.
     * @return string
     */
    public static function filename(array $response): string {
        \webservice_mcp\local\wrapper\moodle_lib::load('lib/filelib.php');
        $type = strtolower(trim(explode(';', (string)($response['contenttype'] ?? ''))[0]));
        $name = clean_param((string)($response['filename'] ?? ''), PARAM_FILE);
        if ($name === '') {
            $path = (string)parse_url((string)($response['url'] ?? ''), PHP_URL_PATH);
            $last = clean_param(rawurldecode(basename($path)), PARAM_FILE);
            $name = ($last !== '' && pathinfo($last, PATHINFO_EXTENSION) !== '' && pathinfo($last, PATHINFO_EXTENSION) !== 'php')
                ? $last : 'download';
        }
        // Returns ".csv", or ".xxx" for unknown types.
        $extension = $type !== '' ? (string)mimeinfo_from_type('extension', $type) : '';
        if (pathinfo($name, PATHINFO_EXTENSION) === '' && $extension !== '' && $extension !== '.xxx') {
            $name .= $extension;
        }
        return $name;
    }
}
