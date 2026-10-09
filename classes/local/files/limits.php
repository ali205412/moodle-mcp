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
 * Admin-configurable file limits with safe defaults and hard ceilings.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class limits {
    /** Setting name => [default, maximum]. */
    private const SETTINGS = [
        'downloadticketttl' => [900, 86400],
        'uploadticketttl' => [3600, 86400],
        'inlinetextmaxbytes' => [262144, 4194304],
        'inlinebinarymaxbytes' => [5242880, 20971520],
        'uploadinlinemaxbytes' => [15728640, 52428800],
        'uploadfromurlmaxbytes' => [104857600, PHP_INT_MAX],
        'uploadmaxbytes' => [2147483648, PHP_INT_MAX],
        'uploadmaxpartials' => [5, 1000],
        'uploadmaxpartialbytes' => [0, PHP_INT_MAX],
        'renderimagemaxbytes' => [4194304, 20971520],
    ];

    /**
     * Read a setting, falling back to the default and clamping to [1, maximum].
     *
     * @param string $name Setting name (a key of SETTINGS).
     * @return int
     */
    public static function get(string $name): int {
        [$default, $max] = self::SETTINGS[$name];
        $value = (int)get_config('webservice_mcp', $name);
        if ($value <= 0) {
            $value = $default;
        }
        return min($value, $max);
    }

    /**
     * Bytes all of a user's unfinished chunked uploads may hold: the setting, or twice the user's upload limit.
     *
     * @param int $maxupload The user's upload limit, -1 when unlimited.
     * @return int Bytes, or -1 for no cap.
     */
    public static function partial_bytes(int $maxupload): int {
        $configured = self::get('uploadmaxpartialbytes');
        if ($configured > 0) {
            return $configured;
        }
        return $maxupload === USER_CAN_IGNORE_FILE_SIZE_LIMITS ? USER_CAN_IGNORE_FILE_SIZE_LIMITS : 2 * $maxupload;
    }

    /**
     * Clamp a caller-requested ticket lifetime: callers may shorten but never extend the configured lifetime.
     *
     * @param string $name downloadticketttl or uploadticketttl.
     * @param int|null $requested Requested seconds, or null for the default.
     * @return int
     */
    public static function ttl(string $name, ?int $requested): int {
        $configured = self::get($name);
        if ($requested === null || $requested <= 0) {
            return $configured;
        }
        return max(60, min($requested, $configured));
    }

    /**
     * The user's upload size limit in a context.
     *
     * A nonzero site or course maxbytes applies as in core (the smaller wins). When both are 0, this plugin's
     * uploadmaxbytes (default 2 GB) applies instead of PHP's upload_max_filesize/post_max_size: those only bound
     * multipart posts, not the streamed PUT bodies, inline uploads and URL fetches used here, and would cap a
     * default site at a couple of megabytes. -1 (unlimited) only for moodle/course:ignorefilesizelimits, as in core.
     *
     * @param \context $context Context the file is for.
     * @return int Bytes, or USER_CAN_IGNORE_FILE_SIZE_LIMITS (-1).
     */
    public static function max_upload_bytes(\context $context): int {
        global $CFG, $DB;

        if (has_capability('moodle/course:ignorefilesizelimits', $context)) {
            return USER_CAN_IGNORE_FILE_SIZE_LIMITS;
        }
        $limits = [(int)$CFG->maxbytes];
        if ($coursecontext = $context->get_course_context(false)) {
            $limits[] = (int)$DB->get_field('course', 'maxbytes', ['id' => $coursecontext->instanceid]);
        }
        $limits = array_filter($limits, static fn(int $bytes) => $bytes > 0);
        return $limits ? min($limits) : self::get('uploadmaxbytes');
    }
}
