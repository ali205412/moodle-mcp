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

use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Extracts one HTML form as structured fields, understanding Moodle's form markup.
 *
 * Handles moodleforms (fitem rows with labels, required markers, help popovers and per-field errors, editors as
 * name[text|format|itemid], date selectors as name[day|month|year|hour|minute|enabled], file managers with their
 * draft item id and limits, advcheckbox hidden/checkbox pairs), admin settings pages (form-item rows with
 * descriptions and defaults) and plain HTML forms.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class form_parser {
    /** Input types that are buttons, not fields. */
    private const BUTTON_TYPES = ['submit', 'image', 'button', 'reset'];

    /** Date selector parts. */
    private const DATE_PARTS = ['day', 'month', 'year', 'hour', 'minute', 'enabled', 'calendar'];

    /** @var DOMXPath */
    private DOMXPath $xpath;

    /** @var string Page URL forms resolve against. */
    private string $base;

    /**
     * Constructor.
     *
     * @param DOMXPath $xpath XPath of the page document.
     * @param string $base Page URL.
     */
    public function __construct(DOMXPath $xpath, string $base) {
        $this->xpath = $xpath;
        $this->base = $base;
    }

    /**
     * Parse a form.
     *
     * @param DOMElement $form Form element.
     * @return array name, action, method, enctype, buttons, fields.
     */
    public function parse(DOMElement $form): array {
        $controls = [];
        foreach ($this->xpath->query('.//input | .//select | .//textarea | .//button', $form) as $control) {
            if ($control instanceof DOMElement && $this->owner_form($control) === $form) {
                $controls[] = $control;
            }
        }
        // Controls outside the form element that point at it with form="id".
        if ($form->getAttribute('id') !== '') {
            foreach ($this->xpath->query('//*[@form="' . $form->getAttribute('id') . '"]') as $control) {
                $controls[] = $control;
            }
        }

        $buttons = [];
        $fields = [];
        $groups = [];
        foreach ($controls as $control) {
            $tag = strtolower($control->tagName);
            $type = $tag === 'input' ? strtolower($control->getAttribute('type') ?: 'text') : $tag;
            if ($tag === 'button' || in_array($type, self::BUTTON_TYPES, true)) {
                $buttontype = $tag === 'button' ? strtolower($control->getAttribute('type') ?: 'submit') : $type;
                if (in_array($buttontype, ['submit', 'image'], true)) {
                    $buttons[] = $this->button($control);
                }
                continue;
            }
            $name = $control->getAttribute('name');
            if ($name === '') {
                continue;
            }
            // Group compound Moodle elements under their base name.
            if (preg_match('/^(.+)\[(text|format|itemid)\]$/', $name, $m) && $this->is_editor($form, $m[1])) {
                $groups['editor'][$m[1]][$m[2]] = $control;
                $fields[$m[1]] ??= null;
                continue;
            }
            if (preg_match('/^(.+)\[(' . implode('|', self::DATE_PARTS) . ')\]$/', $name, $m) && $this->is_date($control)) {
                $groups['date'][$m[1]][$m[2]] = $control;
                $fields[$m[1]] ??= null;
                continue;
            }
            if ($type === 'radio') {
                $groups['radio'][$name][] = $control;
                $fields[$name] ??= null;
                continue;
            }
            if ($type === 'hidden' && isset($fields[$name]) && $fields[$name]['type'] === 'hidden') {
                continue;
            }
            if ($type === 'checkbox' && isset($fields[$name]) && $fields[$name]['type'] === 'hidden') {
                // Moodle advcheckbox: a hidden input with the unchecked value precedes the checkbox.
                $unchecked = $fields[$name]['value'];
                unset($fields[$name]);
                $fields[$name] = $this->field($control, $type) + ['uncheckedvalue' => $unchecked];
                continue;
            }
            $key = isset($fields[$name]) && $type === 'checkbox' ? $name . '#' . count($fields) : $name;
            $fields[$key] = $this->field($control, $type);
        }
        foreach ($groups['radio'] ?? [] as $name => $radios) {
            $fields[$name] = $this->radio_field($name, $radios);
        }
        foreach ($groups['editor'] ?? [] as $name => $parts) {
            $fields[$name] = $this->editor_field($name, $parts);
        }
        foreach ($groups['date'] ?? [] as $name => $parts) {
            $fields[$name] = $this->date_field($name, $parts);
        }

        $method = strtolower($form->getAttribute('method')) === 'post' ? 'post' : 'get';
        return array_filter([
            'name' => $form->getAttribute('name') ?: null,
            'formid' => $form->getAttribute('id') ?: null,
            'action' => page_parser::resolve($form->getAttribute('action'), $this->base),
            'method' => $method,
            'enctype' => $method === 'post' ? (strtolower($form->getAttribute('enctype')) ?: 'application/x-www-form-urlencoded')
                : null,
            'buttons' => $buttons,
            'fields' => array_values(array_filter($fields)),
        ], static fn($v) => $v !== null);
    }

    /**
     * Describe a single control.
     *
     * @param DOMElement $control Control.
     * @param string $type Input type or tag.
     * @return array
     */
    private function field(DOMElement $control, string $type): array {
        $item = $this->item($control);
        $field = ['name' => $control->getAttribute('name'), 'type' => $type, 'label' => $this->label($control, $item)];
        if ($type === 'select') {
            $field['options'] = $this->options($control);
            $field['multiple'] = $control->hasAttribute('multiple');
            $selected = array_column(array_filter($field['options'], fn($o) => $o['selected']), 'value');
            $field['value'] = $field['multiple'] ? $selected : ($selected[0] ?? ($field['options'][0]['value'] ?? ''));
            $fieldtype = $item ? $this->fieldtype($item) : '';
            if ($fieldtype !== '' && $fieldtype !== 'select') {
                // E.g. autocomplete, selectyesno, modgrade: same submission, useful to know.
                $field['moodletype'] = $fieldtype;
            }
            if ($fieldtype === 'tags' || $control->getAttribute('data-tags') === '1') {
                $field['tags'] = true;
            }
        } else if ($type === 'textarea') {
            $field['value'] = $control->textContent;
        } else if ($type === 'checkbox') {
            $field['value'] = $control->hasAttribute('value') ? $control->getAttribute('value') : 'on';
            $field['checked'] = $control->hasAttribute('checked');
            // Checkboxes in a group row ("Submission types") carry their own label ("Online text").
            $own = $this->first('ancestor::label[1]', $control) ? $this->own_label($control) : '';
            if ($own !== '' && $own !== $field['label']) {
                $field['label'] = $field['label'] !== '' && $item && strpos((string)$item->getAttribute('id'), 'fgroup_') === 0
                    ? $field['label'] . ': ' . $own : $own;
            }
        } else if ($type === 'password') {
            $field['value'] = '';
        } else {
            $field['value'] = $control->getAttribute('value');
        }
        if ($type === 'hidden') {
            $filetype = $item ? $this->fieldtype($item) : '';
            if (in_array($filetype, ['filemanager', 'filepicker'], true)) {
                // The visible picker is JavaScript; the hidden input carries the draft item id.
                $field['type'] = $filetype;
                $field['filemanager'] = $this->filemanager($item, (string)$field['value']);
            } else {
                $field['hidden'] = true;
            }
        }
        return $this->decorate($field, $control, $item);
    }

    /**
     * Radio group as one field.
     *
     * @param string $name Name.
     * @param DOMElement[] $radios Radios.
     * @return array
     */
    private function radio_field(string $name, array $radios): array {
        $item = $this->item($radios[0]);
        $options = [];
        $value = null;
        foreach ($radios as $radio) {
            $label = $this->own_label($radio) ?: $radio->getAttribute('value');
            $options[] = ['value' => $radio->getAttribute('value'), 'label' => $label,
                'selected' => $radio->hasAttribute('checked')];
            if ($radio->hasAttribute('checked')) {
                $value = $radio->getAttribute('value');
            }
        }
        $field = ['name' => $name, 'type' => 'radio', 'label' => $this->group_label($radios[0], $item), 'value' => $value,
            'options' => $options];
        return $this->decorate($field, $radios[0], $item);
    }

    /**
     * Moodle editor (Atto/TinyMCE/plain): text plus format and draft item id.
     *
     * @param string $name Base name.
     * @param DOMElement[] $parts text, format, itemid controls.
     * @return array
     */
    private function editor_field(string $name, array $parts): array {
        $text = $parts['text'] ?? null;
        $item = $this->item($text ?? reset($parts));
        $format = $parts['format'] ?? null;
        $field = ['name' => $name, 'type' => 'editor', 'label' => $text ? $this->label($text, $item) : '',
            'value' => $text ? $text->textContent : '', 'editor' => true];
        if ($format) {
            if (strtolower($format->tagName) === 'select') {
                $options = $this->options($format);
                $field['formatoptions'] = $options;
                $field['format'] = (array_column(array_filter($options, fn($o) => $o['selected']), 'value')[0]
                    ?? ($options[0]['value'] ?? '1'));
            } else {
                $field['format'] = $format->getAttribute('value');
            }
        }
        if (isset($parts['itemid'])) {
            $field['itemid'] = $parts['itemid']->getAttribute('value');
        }
        return $this->decorate($field, $text ?? reset($parts), $item);
    }

    /**
     * Moodle date or date-time selector.
     *
     * @param string $name Base name.
     * @param DOMElement[] $parts Part controls.
     * @return array
     */
    private function date_field(string $name, array $parts): array {
        $item = $this->item(reset($parts));
        $value = [];
        foreach ($parts as $part => $control) {
            if (strtolower($control->tagName) === 'select') {
                $selected = array_column(array_filter($this->options($control), fn($o) => $o['selected']), 'value');
                $value[$part] = $selected[0] ?? '';
            } else if ($part === 'enabled') {
                $value[$part] = $control->hasAttribute('checked') ? '1' : '0';
            } else {
                $value[$part] = $control->getAttribute('value');
            }
        }
        $field = ['name' => $name, 'type' => isset($parts['hour']) ? 'date_time' : 'date',
            'label' => $this->group_label(reset($parts), $item), 'value' => $value, 'optional' => isset($parts['enabled'])];
        if (isset($value['year'], $value['month'], $value['day'])) {
            $field['display'] = sprintf('%04d-%02d-%02d', (int)$value['year'], (int)$value['month'], (int)$value['day'])
                . (isset($value['hour']) ? sprintf(' %02d:%02d', (int)$value['hour'], (int)($value['minute'] ?? 0)) : '')
                . (isset($parts['enabled']) && $value['enabled'] !== '1' ? ' (disabled)' : '');
        }
        if (isset($parts['year'])) {
            $years = array_column($this->options($parts['year']), 'value');
            $field['yearrange'] = $years ? [min($years), max($years)] : null;
        }
        return $this->decorate($field, reset($parts), $item);
    }

    /**
     * Add required, help, error, section and disabled to a field.
     *
     * @param array $field Field.
     * @param DOMElement $control Main control.
     * @param DOMElement|null $item Containing fitem/form-item row.
     * @return array
     */
    private function decorate(array $field, DOMElement $control, ?DOMElement $item): array {
        $required = $control->hasAttribute('required') || $control->getAttribute('aria-required') === 'true'
            || ($item && $this->first('.//*[' . page_parser::cls('form-label-addon') . ']//*[' . page_parser::cls('text-danger')
                . ']', $item));
        if ($required) {
            $field['required'] = true;
        }
        if ($control->hasAttribute('disabled')) {
            $field['disabled'] = true;
        }
        if ($item && ($help = $this->help($item)) !== '') {
            $field['help'] = $help;
        }
        $error = $this->first('.//*[@id="id_error_' . $this->idsafe($field['name']) . '" or @id="fgroup_id_error_'
            . $this->idsafe($field['name']) . '"]', $item ?? $control->ownerDocument->documentElement);
        if ($error && ($text = $this->clean($error->textContent)) !== '') {
            $field['error'] = $text;
        }
        $legend = $this->first('ancestor::fieldset[' . page_parser::cls('collapsible') . ' or ' . page_parser::cls('clearfix')
            . ']/legend', $control);
        if ($legend && ($section = $this->clean($legend->textContent)) !== '') {
            $field['section'] = $section;
        }
        if ($field['label'] === '') {
            unset($field['label']);
        }
        return $field;
    }

    /**
     * File manager or file picker limits from its no-JS fallback URL and accepted-types list.
     *
     * @param DOMElement $item Field row.
     * @param string $draftitemid Current draft item id.
     * @return array
     */
    private function filemanager(DOMElement $item, string $draftitemid): array {
        $info = ['draftitemid' => (int)$draftitemid];
        $object = $this->first('.//object[contains(@data, "draftfiles_manager.php") or contains(@data, "filepicker.php")]', $item);
        if ($object instanceof DOMElement) {
            parse_str((string)parse_url(html_entity_decode($object->getAttribute('data')), PHP_URL_QUERY), $query);
            foreach (['maxfiles', 'maxbytes', 'areamaxbytes', 'subdirs'] as $key) {
                if (isset($query[$key]) && is_numeric($query[$key])) {
                    $info[$key] = (int)$query[$key];
                }
            }
        }
        $types = [];
        foreach ($this->xpath->query('.//*[' . page_parser::cls('form-filetypes-descriptions') . ']//small', $item) as $small) {
            $types = array_merge($types, preg_split('/\s+/', trim($small->textContent)) ?: []);
        }
        if ($types) {
            $info['accepted_types'] = array_values(array_unique(array_filter($types)));
        }
        return $info;
    }

    /**
     * Submit button.
     *
     * @param DOMElement $control Button or input.
     * @return array
     */
    private function button(DOMElement $control): array {
        $isinput = strtolower($control->tagName) === 'input';
        $label = $isinput ? $control->getAttribute('value') : $this->clean($control->textContent);
        $class = ' ' . preg_replace('/\s+/', ' ', $control->getAttribute('class')) . ' ';
        return array_filter([
            'name' => $control->getAttribute('name'),
            'value' => $control->hasAttribute('value') ? $control->getAttribute('value') : $label,
            'label' => $label ?: ($control->getAttribute('aria-label') ?: $control->getAttribute('title')),
            'primary' => strpos($class, ' btn-primary ') !== false,
            'cancel' => $control->getAttribute('name') === 'cancel' || $control->hasAttribute('data-cancel'),
            // Shown only by JavaScript (e.g. "Update format" after changing the course format).
            'hidden' => (bool)preg_match('/ (d-none|hidden) /', $class),
        ], static fn($v) => $v !== false);
    }

    /**
     * Options of a select (optgroup labels prefixed).
     *
     * @param DOMElement $select Select.
     * @return array [{value, label, selected}]
     */
    private function options(DOMElement $select): array {
        $options = [];
        foreach ($this->xpath->query('.//option', $select) as $option) {
            if (!$option instanceof DOMElement) {
                continue;
            }
            $label = $this->clean($option->textContent);
            $group = $option->parentNode instanceof DOMElement && strtolower($option->parentNode->tagName) === 'optgroup'
                ? $this->clean($option->parentNode->getAttribute('label')) : '';
            $options[] = ['value' => $option->hasAttribute('value') ? $option->getAttribute('value') : $label,
                'label' => $group !== '' ? "{$group} / {$label}" : $label, 'selected' => $option->hasAttribute('selected')];
        }
        return $options;
    }

    /**
     * Label of a control: <label for>, the row's label, aria-label, a wrapping label, title or placeholder.
     *
     * @param DOMElement $control Control.
     * @param DOMElement|null $item Field row.
     * @return string
     */
    private function label(DOMElement $control, ?DOMElement $item): string {
        $id = $control->getAttribute('id');
        if ($id !== '' && ($label = $this->first('//label[@for="' . $id . '"]'))) {
            $text = $this->clean($label->textContent);
            if ($text !== '') {
                return $text;
            }
        }
        if ($item && ($label = $this->first('.//*[substring(@id, string-length(@id) - 5) = "_label"]', $item))) {
            return $this->clean($label->textContent);
        }
        return $this->clean($control->getAttribute('aria-label')) ?: $this->own_label($control)
            ?: $this->clean($control->getAttribute('title') ?: $control->getAttribute('placeholder'));
    }

    /**
     * Label for a group (radios, date selector): the row label or the fieldset legend.
     *
     * @param DOMElement $control A control of the group.
     * @param DOMElement|null $item Field row.
     * @return string
     */
    private function group_label(DOMElement $control, ?DOMElement $item): string {
        if (
            $item && ($label = $this->first('.//*[substring(@id, string-length(@id) - 5) = "_label"] | .//label[not(@for)]'
                . ' | .//*[' . page_parser::cls('form-label') . ']/label', $item))
        ) {
            $text = $this->clean($label->textContent);
            if ($text !== '') {
                return $text;
            }
        }
        $legend = $this->first('ancestor::fieldset[1]/legend', $control);
        return $legend ? $this->clean($legend->textContent) : '';
    }

    /**
     * Text of a label wrapping the control, or of a label for it, without the control's own text.
     *
     * @param DOMElement $control Control.
     * @return string
     */
    private function own_label(DOMElement $control): string {
        $wrapping = $this->first('ancestor::label[1]', $control);
        if ($wrapping) {
            return $this->clean($wrapping->textContent);
        }
        $id = $control->getAttribute('id');
        $label = $id !== '' ? $this->first('//label[@for="' . $id . '"]') : null;
        return $label ? $this->clean($label->textContent) : '';
    }

    /**
     * Help text: Moodle help popover content, admin setting description and default.
     *
     * @param DOMElement $item Field row.
     * @return string
     */
    private function help(DOMElement $item): string {
        $parts = [];
        $popover = $this->first('.//*[@data-toggle="popover" or @data-bs-toggle="popover"]', $item);
        if ($popover instanceof DOMElement) {
            $content = $popover->getAttribute('data-content') ?: $popover->getAttribute('data-bs-content');
            $parts[] = $this->clean(strip_tags(str_replace(
                ['</p>', '<br>', '</li>'],
                ["</p>\n", "<br>\n", "</li>\n"],
                html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            )));
        }
        foreach (['form-description', 'form-defaultinfo'] as $class) {
            $node = $this->first('.//*[' . page_parser::cls($class) . ']', $item);
            if ($node) {
                $parts[] = $this->clean($node->textContent);
            }
        }
        return implode(' ', array_filter($parts));
    }

    /**
     * The field row containing a control: a moodleform fitem/fgroup or an admin form-item.
     *
     * @param DOMElement $control Control.
     * @return DOMElement|null
     */
    private function item(DOMElement $control): ?DOMElement {
        $item = $this->first('ancestor::*[(' . page_parser::cls('fitem') . ' and (starts-with(@id, "fitem_") or '
            . 'starts-with(@id, "fgroup_"))) or ' . page_parser::cls('form-item') . '][1]', $control);
        return $item instanceof DOMElement ? $item : null;
    }

    /**
     * The data-fieldtype of a row's element column.
     *
     * @param DOMElement $item Row.
     * @return string
     */
    private function fieldtype(DOMElement $item): string {
        $felement = $this->first('.//*[' . page_parser::cls('felement') . '][@data-fieldtype]', $item);
        return $felement instanceof DOMElement ? $felement->getAttribute('data-fieldtype') : '';
    }

    /**
     * Whether name[text] belongs to a Moodle editor (a format or itemid sibling exists).
     *
     * @param DOMElement $form Form.
     * @param string $base Base name.
     * @return bool
     */
    private function is_editor(DOMElement $form, string $base): bool {
        return (bool)$this->first('.//*[@name="' . $base . '[format]" or @name="' . $base . '[itemid]"]', $form);
    }

    /**
     * Whether a control is part of a Moodle date selector.
     *
     * @param DOMElement $control Control.
     * @return bool
     */
    private function is_date(DOMElement $control): bool {
        return (bool)$this->first('ancestor::*[@data-fieldtype="date_time_selector" or @data-fieldtype="date_selector" or '
            . '@data-fieldtype="date_time" or @data-fieldtype="date" or ' . page_parser::cls('fdate_time_selector') . ' or '
            . page_parser::cls('fdate_selector') . '][1]', $control);
    }

    /**
     * The form a control belongs to.
     *
     * @param DOMElement $control Control.
     * @return DOMNode|null
     */
    private function owner_form(DOMElement $control): ?DOMNode {
        return $this->first('ancestor::form[1]', $control);
    }

    /**
     * Name as used in Moodle element ids (brackets become underscores).
     *
     * @param string $name Name.
     * @return string
     */
    private function idsafe(string $name): string {
        return str_replace(['[', ']', '"'], ['_', '', ''], $name);
    }

    /**
     * First node matching an XPath query.
     *
     * @param string $query Query.
     * @param DOMNode|null $context Context.
     * @return DOMNode|null
     */
    private function first(string $query, ?DOMNode $context = null): ?DOMNode {
        $result = $context ? $this->xpath->query($query, $context) : $this->xpath->query($query);
        return $result && $result->length ? $result->item(0) : null;
    }

    /**
     * Collapse whitespace.
     *
     * @param string $text Text.
     * @return string
     */
    private function clean(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $text)));
    }
}
