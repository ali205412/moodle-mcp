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
use context_course;
use context_module;
use core_external\external_api;

/**
 * Logic behind /webservice/mcp/pluginfile.php: redeem a download ticket and stream the file or export.
 *
 * Single files are served by file_pluginfile() (or send_stored_file() for the user's own drafts), which
 * apply Moodle's full access rules plus ETag, Range and X-Sendfile handling. Exports stream zips.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_handler {
    /**
     * Handle the current request. Does not return on success for file downloads.
     *
     * @return void
     */
    public static function handle(): void {
        try {
            if (!endpoint::cors('GET, HEAD, OPTIONS')) {
                return;
            }
            $auth = self::authorize(required_param('ticket', PARAM_RAW_TRIMMED));
            self::serve($auth['claims'], $auth['context']);
        } catch (\Throwable $e) {
            endpoint::send_error($e);
        }
    }

    /**
     * Redeem a ticket, log the user in and check the target is inside the token's context restriction.
     *
     * @param string $ticket Signed ticket.
     * @return array{claims:array, context:context}
     * @throws transfer_exception
     */
    public static function authorize(string $ticket): array {
        $redeemed = tickets::redeem('dl', $ticket);
        $claims = $redeemed['claims'];
        endpoint::login($redeemed['user'], $redeemed['restriction']);

        switch ($claims['k'] ?? '') {
            case tickets::KIND_FILE:
                $segments = explode('/', ltrim((string)($claims['rp'] ?? ''), '/'));
                $context = context::instance_by_id((int)$segments[0], IGNORE_MISSING);
                if (!$context || count($segments) < 4) {
                    throw new transfer_exception(404, 'filenotfound', 'File not found.');
                }
                locator::check_restriction($context, $redeemed['restriction'], $segments[1], $segments[2]);
                break;
            case tickets::KIND_COURSE_CONTENT:
                $context = context_course::instance((int)($claims['courseid'] ?? 0));
                locator::check_restriction($context, $redeemed['restriction']);
                break;
            case tickets::KIND_ASSIGN_ALL:
                $context = context_module::instance((int)($claims['cmid'] ?? 0));
                locator::check_restriction($context, $redeemed['restriction']);
                break;
            default:
                throw new transfer_exception(401, 'invalidticket', 'Unknown ticket kind.');
        }
        return ['claims' => $claims, 'context' => $context];
    }

    /**
     * Stream the ticket's content.
     *
     * @param array $claims Ticket claims.
     * @param context $context Target context.
     * @return void
     */
    private static function serve(array $claims, context $context): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        foreach (self::security_headers() as $name => $value) {
            header($name . ': ' . $value);
        }
        $forcedownload = self::forcedownload($claims);
        $preview = isset($claims['pv']) ? clean_param((string)$claims['pv'], PARAM_ALPHA) : null;

        if ($claims['k'] === tickets::KIND_FILE && !empty($claims['dr'])) {
            // Drafts are not served by file_pluginfile(); file_browser only exposes the user's own drafts.
            $params = locator::params_from_relativepath((string)$claims['rp'], true);
            $info = $params ? locator::file_info($params) : null;
            $file = $info ? locator::stored_file($info) : null;
            if (!$file) {
                throw new transfer_exception(404, 'filenotfound', 'File not found.');
            }
            send_stored_file($file, 0, 0, $forcedownload, ['preview' => $preview]);
            return;
        }
        if ($claims['k'] === tickets::KIND_FILE) {
            file_pluginfile((string)$claims['rp'], $forcedownload, $preview);
            return;
        }

        external_api::validate_context($context);
        if ($claims['k'] === tickets::KIND_COURSE_CONTENT) {
            self::stream_course_content($context);
        } else {
            self::stream_assign_submissions($context, (int)($claims['groupid'] ?? 0));
        }
    }

    /**
     * Headers sent with every download: user content must never run as script on the Moodle origin.
     *
     * @return array Header name => value.
     */
    public static function security_headers(): array {
        return ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox'];
    }

    /**
     * Whether to send the file as an attachment. Drafts always are, as in core draftfile.php ("security first"),
     * because anyone can put HTML in their own draft area; other areas leave it to file_pluginfile()'s callbacks.
     *
     * @param array $claims Ticket claims.
     * @return bool
     */
    public static function forcedownload(array $claims): bool {
        return !empty($claims['dr']) || !empty($claims['fd']);
    }

    /**
     * Stream a course content export zip (as course/downloadcontent.php).
     *
     * @param context $context Course context.
     * @return void
     */
    private static function stream_course_content(context $context): void {
        global $CFG, $USER;

        if (!\core\content::can_export_context($context, $USER)) {
            throw new transfer_exception(403, 'nopermissions', 'Downloading this course content is not allowed.');
        }
        $course = get_fast_modinfo($context->instanceid)->get_course();
        $filename = str_replace('/', '', str_replace(' ', '_', $course->shortname)) . '_' . time() . '.zip';
        $options = null;
        if (!empty($CFG->maxsizeperdownloadcoursefile)) {
            $options = (object)['maxfilesize' => $CFG->maxsizeperdownloadcoursefile];
        }
        header('X-Accel-Buffering: no');
        $writer = \core\content\export\zipwriter::get_stream_writer($filename, $options);
        \core\content::export_context($context, $USER, $writer);
    }

    /**
     * Stream all assignment submissions as a zip (as the "Download all submissions" action).
     *
     * @param context $context Module context.
     * @param int $groupid Optional group filter.
     * @return void
     */
    private static function stream_assign_submissions(context $context, int $groupid): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$course, $cm] = get_course_and_cm_from_cmid($context->instanceid, 'assign');
        $assign = new \assign($context, $cm, $course);
        $assign->require_view_grades();
        $userids = $groupid ? export_service::group_userids($cm, $groupid) : null;
        $downloader = new \mod_assign\downloader($assign, $userids);
        if (!$downloader->load_filelist()) {
            throw new transfer_exception(404, 'nosubmission', get_string('nosubmission', 'mod_assign'));
        }
        header('X-Accel-Buffering: no');
        $downloader->download_zip();
    }
}
