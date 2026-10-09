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

namespace webservice_mcp\local\catalog;

use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shared JSON schema builder for harvested external function metadata.
 *
 * Input schemas mirror what external_api::validate_parameters() accepts. Output schemas mirror what
 * external_api::clean_returnvalue() can produce, so MCP clients validating structuredContent accept real results.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_builder {
    /**
     * Build an input JSON schema from an external description tree.
     *
     * @param external_description|null $description External description.
     * @return array
     */
    public static function build(?external_description $description): array {
        if ($description === null) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }

        $schema = self::generate($description, false);
        unset($schema['_required']);
        return $schema;
    }

    /**
     * Build an output JSON schema from an external returns description.
     *
     * @param external_description|null $description External returns description.
     * @return array
     */
    public static function build_output(?external_description $description): array {
        if ($description === null) {
            // Functions without a returns description return null; accept any JSON value.
            return ['description' => 'This function returns no structured data.'];
        }

        $schema = self::generate($description, true);
        unset($schema['_required']);
        return $schema;
    }

    /**
     * Recursively generate a schema fragment.
     *
     * @param external_description $param Description node.
     * @param bool $output Whether the schema describes a return value.
     * @return array
     */
    private static function generate(external_description $param, bool $output): array {
        $types = self::schema_types($param, $output);
        // Moodle 4.2 structures have no allownull property.
        if (!empty($param->allownull)) {
            $types[] = 'null';
        }
        $schema = ['type' => count($types) === 1 ? $types[0] : $types];

        if (!empty($param->desc)) {
            $schema['description'] = (string)$param->desc;
        }

        if ($param->required === VALUE_REQUIRED) {
            $schema['_required'] = true;
        }

        if ($param instanceof external_value) {
            if (!$output && $param->required === VALUE_DEFAULT && is_scalar($param->default)) {
                $schema['default'] = self::typed_default($param->type, $param->default);
            }
            return $schema;
        }

        if ($param instanceof external_single_structure) {
            $properties = [];
            $requiredfields = [];

            foreach ($param->keys as $key => $subparam) {
                $subschema = self::generate($subparam, $output);
                if (!empty($subschema['_required'])) {
                    $requiredfields[] = $key;
                }
                unset($subschema['_required']);
                $properties[$key] = $subschema;
            }

            $schema['properties'] = empty($properties) ? new \stdClass() : $properties;
            if ($requiredfields !== []) {
                $schema['required'] = $requiredfields;
            } else if ($output) {
                // A structure whose keys are all optional and absent is returned as an empty PHP array, encoded as [].
                $schema['type'] = array_values(array_unique(array_merge((array)$schema['type'], ['array'])));
            }
            if (!$output) {
                // Moodle's validate_parameters() rejects unexpected keys.
                $schema['additionalProperties'] = false;
            }

            return $schema;
        }

        if ($param instanceof external_multiple_structure) {
            $itemschema = self::generate($param->content, $output);
            unset($itemschema['_required']);
            $schema['items'] = $itemschema;
            if (!$output && $param->required === VALUE_DEFAULT && is_array($param->default)) {
                $schema['default'] = array_values($param->default);
            }
            return $schema;
        }

        return $schema;
    }

    /**
     * Map Moodle external parameter types into JSON schema types.
     *
     * @param external_description $param Description node.
     * @param bool $output Whether the schema describes a return value.
     * @return string[]
     */
    private static function schema_types(external_description $param, bool $output): array {
        if ($param instanceof external_value) {
            return match ($param->type) {
                PARAM_INT => ['integer'],
                PARAM_FLOAT => ['number'],
                PARAM_BOOL => ['boolean'],
                // Many PARAM_* cleaners return non-string scalars unchanged, so returned values may be numbers or booleans.
                default => $output ? ['string', 'number', 'boolean'] : ['string'],
            };
        }

        if ($param instanceof external_multiple_structure) {
            return ['array'];
        }

        return ['object'];
    }

    /**
     * Cast a Moodle default value to the JSON type advertised for its PARAM type.
     *
     * @param string $type PARAM_* type.
     * @param mixed $default Default value.
     * @return mixed
     */
    private static function typed_default(string $type, mixed $default): mixed {
        return match ($type) {
            PARAM_INT => (int)$default,
            PARAM_FLOAT => (float)$default,
            PARAM_BOOL => (bool)$default,
            default => (string)$default,
        };
    }
}
