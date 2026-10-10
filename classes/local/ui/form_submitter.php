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

use webservice_mcp\local\files\transfer_exception;

/**
 * Builds the request a browser would send for a parsed form, with the caller's values applied.
 *
 * Starts from what the page would submit untouched (hidden fields, current values, checked boxes and radios,
 * selected options), applies values by field name or label, validates them against the form (unknown names,
 * select/radio options, date ranges, draft item ids), and expands Moodle editors and date selectors into their
 * sub-fields. Password fields are only sent when given.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class form_submitter {
    /** Most options listed in an error message. */
    private const MAX_LISTED = 30;

    /** Text-like input types sent as their value. */
    private const SCALAR_TYPES = ['hidden', 'text', 'textarea', 'number', 'email', 'url', 'search', 'tel', 'color', 'range',
        'date', 'datetime-local', 'time', 'month', 'week'];

    /**
     * Build a submission.
     *
     * @param array $form A form from page_parser::parse().
     * @param array $values Field name (or label) => value.
     * @param string|null $button Submit button name, value or label; null for the form's primary button.
     * @return array{method: string, action: string, fields: array<string, string>, multipart: bool}
     * @throws transfer_exception On unknown fields or invalid values, naming what is allowed.
     */
    public static function build(array $form, array $values, ?string $button = null): array {
        $fields = [];
        $byname = [];
        foreach ($form['fields'] as $field) {
            $byname[$field['name']][] = $field;
            if (empty($field['disabled'])) {
                self::apply_default($fields, $field);
            }
        }

        foreach ($values as $key => $value) {
            $targets = $byname[(string)$key] ?? self::by_label($form['fields'], (string)$key);
            if (!$targets) {
                throw self::invalid("Unknown field '{$key}'. Fields: " . self::describe_fields($form['fields']) . '.');
            }
            if (!empty($targets[0]['disabled'])) {
                throw self::invalid("Field '{$key}' is disabled on this page.");
            }
            if (count($targets) > 1 && $targets[0]['type'] === 'checkbox') {
                self::apply_checkbox_group($fields, $targets, $value);
            } else {
                self::apply_value($fields, $targets[0], $value);
            }
        }

        $chosen = self::choose_button($form['buttons'] ?? [], $button);
        if ($chosen !== null && ($chosen['name'] ?? '') !== '') {
            $fields[$chosen['name']] = (string)($chosen['value'] ?? '');
        }

        return [
            'method' => ($form['method'] ?? 'get') === 'post' ? 'post' : 'get',
            'action' => $form['action'],
            'fields' => self::flatten($fields),
            'multipart' => ($form['enctype'] ?? '') === 'multipart/form-data',
        ];
    }

    /**
     * What the browser would send for a field left untouched.
     *
     * @param array $fields Submission being built.
     * @param array $field Field.
     * @return void
     */
    private static function apply_default(array &$fields, array $field): void {
        $name = $field['name'];
        switch ($field['type']) {
            case 'password':
            case 'file':
                return;
            case 'checkbox':
                if (!empty($field['checked'])) {
                    self::put($fields, $name, (string)$field['value']);
                } else if (isset($field['uncheckedvalue'])) {
                    self::put($fields, $name, (string)$field['uncheckedvalue']);
                }
                return;
            case 'radio':
                if ($field['value'] !== null) {
                    $fields[$name] = (string)$field['value'];
                }
                return;
            case 'select':
                $fields[$name] = $field['value'];
                return;
            case 'editor':
                $fields[$name . '[text]'] = (string)$field['value'];
                if (isset($field['format'])) {
                    $fields[$name . '[format]'] = (string)$field['format'];
                }
                if (isset($field['itemid'])) {
                    $fields[$name . '[itemid]'] = (string)$field['itemid'];
                }
                return;
            case 'date':
            case 'date_time':
                foreach ($field['value'] as $part => $value) {
                    if ($part !== 'enabled' || $value === '1') {
                        $fields[$name . '[' . $part . ']'] = (string)$value;
                    }
                }
                return;
            default:
                $fields[$name] = (string)($field['value'] ?? '');
        }
    }

    /**
     * Apply a caller value to a field.
     *
     * @param array $fields Submission being built.
     * @param array $field Field.
     * @param mixed $value Value.
     * @return void
     */
    private static function apply_value(array &$fields, array $field, $value): void {
        $name = $field['name'];
        switch ($field['type']) {
            case 'select':
                if (!empty($field['multiple'])) {
                    $list = is_array($value) ? array_values($value) : [$value];
                    $fields[$name] = array_map(fn($v) => self::option($field, $v), $list);
                } else {
                    $fields[$name] = self::option($field, $value);
                }
                return;
            case 'radio':
                $fields[$name] = self::option($field, $value);
                return;
            case 'checkbox':
                self::remove($fields, $name, (string)$field['value']);
                if (self::truthy($field, $value)) {
                    self::put($fields, $name, (string)$field['value']);
                } else if (isset($field['uncheckedvalue'])) {
                    self::put($fields, $name, (string)$field['uncheckedvalue']);
                }
                return;
            case 'editor':
                $parts = is_array($value) ? $value : ['text' => $value];
                foreach (array_diff(array_keys($parts), ['text', 'format', 'itemid']) as $unknown) {
                    throw self::invalid("Editor '{$name}' takes text, format and itemid, not '{$unknown}'.");
                }
                if (isset($parts['text'])) {
                    $fields[$name . '[text]'] = self::scalar($name, $parts['text']);
                }
                if (isset($parts['format'])) {
                    $fields[$name . '[format]'] = isset($field['formatoptions'])
                        ? self::option(['name' => $name . '[format]', 'options' => $field['formatoptions']], $parts['format'])
                        : self::scalar($name, $parts['format']);
                }
                if (isset($parts['itemid'])) {
                    $fields[$name . '[itemid]'] = self::draftitemid($name, $parts['itemid']);
                }
                return;
            case 'date':
            case 'date_time':
                foreach (array_keys($fields) as $key) {
                    if (strpos((string)$key, $name . '[') === 0) {
                        unset($fields[$key]);
                    }
                }
                foreach (self::date_parts($field, $value) as $part => $partvalue) {
                    $fields[$name . '[' . $part . ']'] = (string)$partvalue;
                }
                return;
            case 'filemanager':
            case 'filepicker':
                $fields[$name] = self::draftitemid($name, $value);
                return;
            case 'file':
                throw self::invalid("Field '{$name}' is a browser file upload; upload the file with the file tools and use a "
                    . 'form field that takes a draft item id instead.');
            default:
                if (!in_array($field['type'], array_merge(self::SCALAR_TYPES, ['password']), true)) {
                    throw self::invalid("Field '{$name}' has an unsupported type ({$field['type']}).");
                }
                $fields[$name] = self::scalar($name, $value);
        }
    }

    /**
     * Tick exactly the given values of a same-name checkbox group (e.g. name[]).
     *
     * @param array $fields Submission being built.
     * @param array $targets Checkbox fields sharing the name.
     * @param mixed $value Values to tick.
     * @return void
     */
    private static function apply_checkbox_group(array &$fields, array $targets, $value): void {
        $wanted = array_map('strval', is_array($value) ? $value : [$value]);
        $name = $targets[0]['name'];
        unset($fields[$name]);
        foreach ($targets as $target) {
            $matches = in_array((string)$target['value'], $wanted, true)
                || (isset($target['label']) && in_array(
                    \core_text::strtolower($target['label']),
                    array_map('\core_text::strtolower', $wanted),
                    true
                ));
            if ($matches) {
                self::put($fields, $name, (string)$target['value']);
            }
        }
    }

    /**
     * Match a value to a select or radio option by value, then by label (case-insensitive).
     *
     * @param array $field Field with options.
     * @param mixed $value Value or label.
     * @return string Option value.
     */
    private static function option(array $field, $value): string {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (!is_scalar($value)) {
            throw self::invalid("Field '{$field['name']}' needs a single option value.");
        }
        $value = (string)$value;
        foreach ($field['options'] as $option) {
            if ((string)$option['value'] === $value) {
                return $value;
            }
        }
        foreach ($field['options'] as $option) {
            if (\core_text::strtolower($option['label']) === \core_text::strtolower($value)) {
                return (string)$option['value'];
            }
        }
        if (!empty($field['tags'])) {
            // Tag fields accept new values.
            return $value;
        }
        $listed = array_map(fn($o) => "{$o['value']} ({$o['label']})", array_slice($field['options'], 0, self::MAX_LISTED));
        throw self::invalid("'{$value}' is not an option of '{$field['name']}'. Options: " . implode(', ', $listed)
            . (count($field['options']) > self::MAX_LISTED ? ', …' : '') . '.');
    }

    /**
     * Date selector parts from a timestamp, a date string (in the user's timezone), parts, or null to disable.
     *
     * @param array $field Date field.
     * @param mixed $value Value.
     * @return array Part => value.
     */
    private static function date_parts(array $field, $value): array {
        $name = $field['name'];
        $optional = !empty($field['optional']);
        if ($value === null || $value === false || $value === '') {
            if (!$optional) {
                throw self::invalid("Date '{$name}' is required and cannot be disabled.");
            }
            return array_diff_key($field['value'], ['enabled' => 1]);
        }
        if (is_array($value)) {
            $parts = array_intersect_key($value, array_flip(['day', 'month', 'year', 'hour', 'minute']));
            $parts = array_map('intval', $parts) + array_map('intval', array_diff_key($field['value'], ['enabled' => 1]));
        } else {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $timestamp = (int)$value;
            } else {
                try {
                    $timestamp = (new \DateTime((string)$value, \core_date::get_user_timezone_object()))->getTimestamp();
                } catch (\Exception $e) {
                    throw self::invalid("Date '{$name}' needs a timestamp, a date such as '2026-10-31 17:00', or "
                        . '{day, month, year, hour, minute}.');
                }
            }
            $date = usergetdate($timestamp);
            $parts = ['day' => $date['mday'], 'month' => $date['mon'], 'year' => $date['year'],
                'hour' => $date['hours'], 'minute' => $date['minutes']];
        }
        if ($field['type'] === 'date') {
            unset($parts['hour'], $parts['minute']);
        }
        $range = $field['yearrange'] ?? null;
        if ($range && ($parts['year'] < (int)$range[0] || $parts['year'] > (int)$range[1])) {
            throw self::invalid("Year {$parts['year']} is outside the range of '{$name}' ({$field['yearrange'][0]}-"
                . "{$field['yearrange'][1]}).");
        }
        if (!checkdate((int)$parts['month'], (int)$parts['day'], (int)$parts['year'])) {
            throw self::invalid("'{$name}' is not a valid date.");
        }
        return ($optional ? ['enabled' => 1] : []) + $parts;
    }

    /**
     * Whether a checkbox value means ticked.
     *
     * @param array $field Checkbox.
     * @param mixed $value Value.
     * @return bool
     */
    private static function truthy(array $field, $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        $text = \core_text::strtolower(trim((string)(is_scalar($value) || $value === null ? $value : '')));
        $on = \core_text::strtolower((string)$field['value']);
        if (in_array($text, ['1', 'true', 'yes', 'on', 'checked'], true) || $text === $on) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off', 'unchecked', ''], true)) {
            return false;
        }
        throw self::invalid("Checkbox '{$field['name']}' takes true or false.");
    }

    /**
     * A draft item id prepared with the file tools.
     *
     * @param string $name Field name.
     * @param mixed $value Value.
     * @return string
     */
    private static function draftitemid(string $name, $value): string {
        if (!is_numeric($value) || (int)$value <= 0) {
            throw self::invalid("Field '{$name}' takes a draftitemid: upload files with file_upload or file_create_upload_url "
                . 'first and pass the draftitemid they return.');
        }
        return (string)(int)$value;
    }

    /**
     * A scalar value as a string.
     *
     * @param string $name Field name.
     * @param mixed $value Value.
     * @return string
     */
    private static function scalar(string $name, $value): string {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (!is_scalar($value) && $value !== null) {
            throw self::invalid("Field '{$name}' takes a single value, not a list.");
        }
        return (string)$value;
    }

    /**
     * Fields matching a label (case-insensitive), when exactly one field has it.
     *
     * @param array $fields Fields.
     * @param string $label Label.
     * @return array
     */
    private static function by_label(array $fields, string $label): array {
        $matches = array_values(array_filter($fields, fn($f) => isset($f['label'])
            && \core_text::strtolower($f['label']) === \core_text::strtolower(trim($label))));
        return count($matches) === 1 ? $matches : [];
    }

    /**
     * Pick the submit button: the requested one, else the visible primary one, else the first visible non-cancel one.
     *
     * @param array $buttons Buttons.
     * @param string|null $wanted Name, value or label.
     * @return array|null
     */
    private static function choose_button(array $buttons, ?string $wanted): ?array {
        if ($wanted !== null && $wanted !== '') {
            foreach (['name', 'value', 'label'] as $key) {
                foreach ($buttons as $button) {
                    if (isset($button[$key]) && \core_text::strtolower((string)$button[$key]) === \core_text::strtolower($wanted)) {
                        return $button;
                    }
                }
            }
            $labels = array_map(fn($b) => $b['label'] ?? $b['value'] ?? $b['name'], $buttons);
            throw self::invalid("No button '{$wanted}' in this form. Buttons: " . (implode(', ', $labels) ?: 'none') . '.');
        }
        $usable = array_values(array_filter($buttons, fn($b) => empty($b['hidden']) && empty($b['cancel'])));
        foreach ($usable as $button) {
            if (!empty($button['primary'])) {
                return $button;
            }
        }
        return $usable[0] ?? null;
    }

    /**
     * Expand list values into indexed names (name[] or name => name[0], name[1]), as PHP parses them.
     *
     * @param array $fields Name => string|array.
     * @return array<string, string>
     */
    private static function flatten(array $fields): array {
        $flat = [];
        foreach ($fields as $name => $value) {
            if (!is_array($value)) {
                $flat[(string)$name] = $value;
                continue;
            }
            $base = substr((string)$name, -2) === '[]' ? substr((string)$name, 0, -2) : (string)$name;
            foreach (array_values($value) as $i => $item) {
                $flat[$base . '[' . $i . ']'] = (string)$item;
            }
        }
        return $flat;
    }

    /**
     * Add a value, appending for name[] fields.
     *
     * @param array $fields Submission.
     * @param string $name Name.
     * @param string $value Value.
     * @return void
     */
    private static function put(array &$fields, string $name, string $value): void {
        if (substr($name, -2) === '[]') {
            $fields[$name] = array_merge((array)($fields[$name] ?? []), [$value]);
        } else {
            $fields[$name] = $value;
        }
    }

    /**
     * Remove a value (one entry of a name[] list, or the whole field).
     *
     * @param array $fields Submission.
     * @param string $name Name.
     * @param string $value Value.
     * @return void
     */
    private static function remove(array &$fields, string $name, string $value): void {
        if (substr($name, -2) === '[]') {
            $fields[$name] = array_values(array_diff((array)($fields[$name] ?? []), [$value]));
        } else {
            unset($fields[$name]);
        }
    }

    /**
     * "name (label)" list of the visible fields, for error messages.
     *
     * @param array $fields Fields.
     * @return string
     */
    private static function describe_fields(array $fields): string {
        $names = [];
        foreach ($fields as $field) {
            if (empty($field['hidden'])) {
                $names[] = $field['name'] . (isset($field['label']) ? " ({$field['label']})" : '');
            }
        }
        return implode(', ', array_unique($names)) ?: 'none';
    }

    /**
     * Invalid-value error.
     *
     * @param string $message Message.
     * @return transfer_exception
     */
    private static function invalid(string $message): transfer_exception {
        return new transfer_exception(400, 'invalidformvalue', $message);
    }
}
