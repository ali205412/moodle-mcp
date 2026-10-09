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

namespace webservice_mcp;

use advanced_testcase;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use webservice_mcp\local\catalog\schema_builder;

/**
 * Tests for JSON schema generation from external descriptions.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\catalog\schema_builder
 */
final class schema_builder_test extends advanced_testcase {
    /**
     * Scalar types map to integer/number/boolean/string, with null when allowed.
     */
    public function test_scalar_types_and_nullability(): void {
        $schema = schema_builder::build(new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
            'ratio' => new external_value(PARAM_FLOAT, 'Ratio', VALUE_DEFAULT, null),
            'flag' => new external_value(PARAM_BOOL, 'Flag', VALUE_DEFAULT, 0, NULL_NOT_ALLOWED),
            'name' => new external_value(PARAM_TEXT, 'Name', VALUE_DEFAULT, 'x'),
        ]));

        $this->assertSame('object', $schema['type']);
        $this->assertArrayNotHasKey('_required', $schema);
        $this->assertSame(['id'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame(['number', 'null'], $schema['properties']['ratio']['type']);
        $this->assertSame('boolean', $schema['properties']['flag']['type']);
        $this->assertFalse($schema['properties']['flag']['default']);
        $this->assertSame(['string', 'null'], $schema['properties']['name']['type']);
        $this->assertSame('x', $schema['properties']['name']['default']);
    }

    /**
     * Required structures and lists are listed in required and carry descriptions.
     */
    public function test_required_structures(): void {
        $schema = schema_builder::build(new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure(['key' => new external_value(PARAM_ALPHA, 'Key')], 'Item'),
                'Items'
            ),
            'options' => new external_single_structure([], 'Options', VALUE_OPTIONAL),
        ]));

        $this->assertSame(['items'], $schema['required']);
        $this->assertSame('Items', $schema['properties']['items']['description']);
        $this->assertSame('Item', $schema['properties']['items']['items']['description']);
        $this->assertSame(['key'], $schema['properties']['items']['items']['required']);
    }

    /**
     * Output schemas accept empty optional structures, null results and loosely typed strings.
     */
    public function test_output_schema_is_permissive_where_moodle_is(): void {
        $schema = schema_builder::build_output(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Id'),
            'extra' => new external_single_structure([
                'note' => new external_value(PARAM_RAW, 'Note', VALUE_OPTIONAL),
            ], 'Extra', VALUE_OPTIONAL),
        ]));

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['id'], $schema['required']);
        $this->assertArrayNotHasKey('additionalProperties', $schema);
        $this->assertSame(['object', 'array'], $schema['properties']['extra']['type']);
        $this->assertSame(['string', 'number', 'boolean', 'null'], $schema['properties']['extra']['properties']['note']['type']);
        $this->assertArrayNotHasKey('type', schema_builder::build_output(null));
    }

    /**
     * A real external function result validates against its generated output schema.
     */
    public function test_real_result_validates_against_output_schema(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $info = external_api::external_function_info('core_webservice_get_site_info');
        $result = external_api::clean_returnvalue($info->returns_desc, call_user_func([$info->classname, $info->methodname]));
        $data = json_decode(json_encode($result));

        $this->assertSame([], $this->validate($data, schema_builder::build_output($info->returns_desc), '$'));
    }

    /**
     * Minimal JSON schema validator for type, required, properties and items.
     *
     * @param mixed $value Decoded JSON value.
     * @param array $schema Schema.
     * @param string $path Path for messages.
     * @return array Errors.
     */
    private function validate(mixed $value, array $schema, string $path): array {
        $jsontype = match (true) {
            is_null($value) => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'object',
        };
        $types = (array)($schema['type'] ?? [$jsontype]);
        if (!in_array($jsontype, $types, true) && !($jsontype === 'integer' && in_array('number', $types, true))) {
            return ["{$path}: {$jsontype} not in " . implode('|', $types)];
        }

        $errors = [];
        if ($jsontype === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (!property_exists($value, $key)) {
                    $errors[] = "{$path}.{$key}: missing";
                }
            }
            foreach ((array)($schema['properties'] ?? []) as $key => $subschema) {
                if (property_exists($value, $key)) {
                    $errors = array_merge($errors, $this->validate($value->$key, $subschema, "{$path}.{$key}"));
                }
            }
        } else if ($jsontype === 'array' && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $errors = array_merge($errors, $this->validate($item, $schema['items'], "{$path}[{$index}]"));
            }
        }

        return $errors;
    }
}
