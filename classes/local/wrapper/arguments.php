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

namespace webservice_mcp\local\wrapper;

/**
 * Coercion helpers for JSON-decoded wrapper tool arguments.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class arguments {
    /**
     * Convert a JSON-ish value into a boolean, treating "false", "0", "off", "no" and "" as false.
     *
     * @param mixed $value Raw value.
     * @param bool $default Value used when the input is null or not boolean-like.
     * @return bool
     */
    public static function to_bool(mixed $value, bool $default = false): bool {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Read a boolean argument.
     *
     * @param array $arguments Arguments.
     * @param string $key Key.
     * @param bool $default Default.
     * @return bool
     */
    public static function flag(array $arguments, string $key, bool $default = false): bool {
        return self::to_bool($arguments[$key] ?? null, $default);
    }

    /**
     * Read an integer argument.
     *
     * @param array $arguments Arguments.
     * @param string $key Key.
     * @param int $default Default.
     * @return int
     */
    public static function integer(array $arguments, string $key, int $default = 0): int {
        return isset($arguments[$key]) && is_scalar($arguments[$key]) ? (int)$arguments[$key] : $default;
    }

    /**
     * Read an optional integer argument.
     *
     * @param array $arguments Arguments.
     * @param string $key Key.
     * @return int|null
     */
    public static function optional_integer(array $arguments, string $key): ?int {
        return isset($arguments[$key]) && is_scalar($arguments[$key]) ? (int)$arguments[$key] : null;
    }

    /**
     * Read a string argument.
     *
     * @param array $arguments Arguments.
     * @param string $key Key.
     * @param string $default Default.
     * @return string
     */
    public static function text(array $arguments, string $key, string $default = ''): string {
        return isset($arguments[$key]) && is_scalar($arguments[$key]) ? (string)$arguments[$key] : $default;
    }

    /**
     * Read an optional string argument.
     *
     * @param array $arguments Arguments.
     * @param string $key Key.
     * @return string|null
     */
    public static function optional_text(array $arguments, string $key): ?string {
        return isset($arguments[$key]) && is_scalar($arguments[$key]) ? (string)$arguments[$key] : null;
    }

    /**
     * Read an array argument.
     *
     * @param array $arguments Arguments.
     * @param string $key Key.
     * @return array
     */
    public static function values(array $arguments, string $key): array {
        return is_array($arguments[$key] ?? null) ? $arguments[$key] : [];
    }

    /**
     * Normalize an id list to unique positive integers.
     *
     * @param array $ids Raw ids.
     * @return int[]
     */
    public static function ids(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_scalar'))));
        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }

    /**
     * Trim a string, returning null when it is null or empty.
     *
     * @param string|null $value Raw string.
     * @return string|null
     */
    public static function trimmed_or_null(?string $value): ?string {
        $value = $value === null ? '' : trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Build an invalid-input exception whose message reaches the client (invalid_parameter_exception keeps details
     * in debuginfo, which is hidden unless developer debugging is on).
     *
     * @param string $message Explanation of what is wrong.
     * @return \moodle_exception
     */
    public static function invalid(string $message): \moodle_exception {
        return new \moodle_exception('wrapper:invalidinput', 'webservice_mcp', '', $message);
    }
}
