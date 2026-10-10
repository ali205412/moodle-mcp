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
 * Submits a Moodle form programmatically, exactly as a browser submission would be processed.
 *
 * The form's current values (after set_data() and its preprocessing) are overlaid with the requested changes,
 * empty inputs are filled the way a browser sends them, each value is cleaned with the element's setType(), and the
 * form's own get_data() runs: QuickForm rules, validation() and any post-processing (editors, data_postprocessing()).
 * No sesskey is involved because nothing reads the HTTP request; callers check capabilities like the UI page does.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class form_submission {
    /** Element types that submit nothing. */
    private const NON_INPUT = ['header', 'static', 'html', 'submit', 'button', 'reset', 'cancel', 'image', 'file'];

    /**
     * Submit a form with changes.
     *
     * @param \moodleform $mform Form, already given its current data with set_data().
     * @param array $changes Field name => new value; timestamps, seconds and strings are accepted for date,
     *     duration and file type fields as get_module_settings returns them.
     * @return array [stdClass|null data, array field => error message]
     */
    public static function submit(\moodleform $mform, array $changes): array {
        self::finalise($mform);
        $quickform = self::quickform($mform);
        $submitted = self::browser_values($quickform);
        foreach ($changes as $name => $value) {
            $name = (string)$name;
            $element = $quickform->elementExists($name) ? $quickform->getElement($name) : null;
            $submitted[$name] = $element ? self::submit_shape($element, $value) : $value;
        }

        // Cleans each value with the element's setType(), as a real submission is cleaned, and marks it submitted.
        $quickform->updateSubmission($submitted, []);
        $data = $mform->get_data();
        if ($data !== null) {
            return [$data, []];
        }

        $errors = array_map(static fn($message): string => trim(strip_tags((string)$message)), (array)$quickform->_errors);
        return [null, $errors ?: ['form' => 'The form was not accepted.']];
    }

    /**
     * The values the form currently shows (what the edit page would display), without internal fields.
     *
     * @param \moodleform $mform Form, already given its current data with set_data().
     * @return array
     */
    public static function current_values(\moodleform $mform): array {
        self::finalise($mform);
        $quickform = self::quickform($mform);
        $quickform->updateSubmission(self::browser_values($quickform), []);
        $values = $quickform->exportValues();
        foreach (array_keys($values) as $name) {
            $name = (string)$name;
            if ($name === 'sesskey' || str_starts_with($name, '_qf__') || str_starts_with($name, 'mform_isexpanded_')) {
                unset($values[$name]);
            }
        }

        return $values;
    }

    /**
     * What a browser would post for the form as currently displayed.
     *
     * @param \HTML_QuickForm $quickform Form.
     * @return array
     */
    private static function browser_values(\HTML_QuickForm $quickform): array {
        $values = [];
        foreach ($quickform->_elements as $element) {
            $values = array_replace($values, self::element_post($element));
        }
        unset($values['sesskey']);

        return $values;
    }

    /**
     * What a browser posts for one element: name => value, nested for groups; nothing for unchecked boxes.
     *
     * @param \HTML_QuickForm_element $element Element.
     * @return array
     */
    private static function element_post(\HTML_QuickForm_element $element): array {
        $name = (string)$element->getName();
        if (in_array($element->getType(), self::NON_INPUT, true)) {
            return [];
        }

        if ($element instanceof \HTML_QuickForm_group) {
            $children = [];
            foreach ($element->getElements() as $child) {
                $children = array_replace($children, self::element_post($child));
            }
            if (!$element->_appendName || $name === '') {
                return $children;
            }
            return $children === [] ? [] : [$name => $children];
        }
        if ($name === '') {
            return [];
        }

        if ($element instanceof \HTML_QuickForm_advcheckbox) {
            return [$name => $element->getValue()];
        }
        if ($element instanceof \HTML_QuickForm_checkbox) {
            return $element->getChecked() ? [$name => $element->getAttribute('value') ?? 1] : [];
        }
        if ($element instanceof \HTML_QuickForm_select) {
            $selected = (array)$element->getValue();
            if ($selected === [] && !$element->getMultiple() && !empty($element->_options)) {
                // A browser submits the first option of a single select with nothing selected.
                $selected = [$element->_options[0]['attr']['value'] ?? ''];
            }
            return [$name => $element->getMultiple() ? array_values($selected) : reset($selected)];
        }

        $value = $element->getValue();
        if ($element->getType() === 'editor') {
            // An empty editor still submits text, format and its draft area.
            $value = (array)$value;
            return [$name => [
                'text' => (string)($value['text'] ?? ''),
                'format' => $value['format'] ?? FORMAT_HTML,
                'itemid' => !empty($value['itemid']) ? $value['itemid'] : \file_get_unused_draft_itemid(),
            ]];
        }
        if (
            is_array($value) && $element->getType() !== 'editor' && method_exists($element, 'getMultiple')
                && !$element->getMultiple()
        ) {
            // Single-choice lists (e.g. grouped selects) keep their selection in an array.
            $value = $value === [] ? '' : reset($value);
        }
        if ($value === null) {
            // Empty inputs are still submitted: '' for text, a fresh draft area for editors and file pickers.
            $value = match ($element->getType()) {
                'filemanager', 'filepicker' => \file_get_unused_draft_itemid(),
                default => '',
            };
        }

        return [$name => $value];
    }

    /**
     * Convert a value as returned by current_values() into what the element's inputs submit.
     *
     * @param \HTML_QuickForm_element $element Element.
     * @param mixed $value Value.
     * @return mixed
     */
    private static function submit_shape(\HTML_QuickForm_element $element, mixed $value): mixed {
        $type = $element->getType();
        if (in_array($type, ['date_selector', 'date_time_selector'], true) && is_numeric($value)) {
            if ((int)$value <= 0) {
                return ['enabled' => 0];
            }
            $date = \usergetdate((int)$value);
            return ['enabled' => 1, 'day' => $date['mday'], 'month' => $date['mon'], 'year' => $date['year'],
                'hour' => $date['hours'], 'minute' => $date['minutes']];
        }
        if ($type === 'duration' && is_numeric($value)) {
            return ['number' => (int)$value, 'timeunit' => 1, 'enabled' => (int)$value > 0 ? 1 : 0];
        }
        if ($type === 'filetypes' && is_string($value)) {
            return ['filetypes' => $value];
        }
        if ($type === 'defaultcustom' && !is_array($value)) {
            return trim((string)$value) === '' ? ['customize' => 0] : ['customize' => 1, 'value' => (string)$value];
        }

        return is_bool($value) ? (int)$value : $value;
    }

    /**
     * Names of the form's fields, for explaining unknown settings.
     *
     * @param \moodleform $mform Form.
     * @return string[]
     */
    public static function field_names(\moodleform $mform): array {
        self::finalise($mform);
        $names = [];
        foreach (self::quickform($mform)->_elements as $element) {
            $name = (string)$element->getName();
            if (
                $name !== '' && !in_array($element->getType(), ['header', 'submit', 'button', 'static', 'html'], true)
                    && $name !== 'sesskey' && !str_starts_with($name, '_qf__')
            ) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Build the invalid-input exception for form errors.
     *
     * @param string $what What was being saved.
     * @param array $errors Field => message.
     * @return \moodle_exception
     */
    public static function rejected(string $what, array $errors): \moodle_exception {
        return arguments::invalid('Invalid ' . $what . ': ' . implode('; ', array_map(
            static fn($field, $message): string => $field . ': ' . $message,
            array_keys($errors),
            array_values($errors)
        )));
    }

    /**
     * Run the form's definition_after_data() now, as get_data() and display() do, so fields it adds are included.
     *
     * @param \moodleform $mform Form.
     * @return void
     */
    private static function finalise(\moodleform $mform): void {
        $finalised = new \ReflectionProperty(\moodleform::class, '_definition_finalized');
        $finalised->setAccessible(true);
        if (!$finalised->getValue($mform)) {
            $finalised->setValue($mform, true);
            $mform->definition_after_data();
        }
    }

    /**
     * The form's QuickForm, which moodleform keeps in a protected property.
     *
     * @param \moodleform $mform Form.
     * @return \MoodleQuickForm
     */
    public static function quickform(\moodleform $mform): \MoodleQuickForm {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        return $property->getValue($mform);
    }
}
