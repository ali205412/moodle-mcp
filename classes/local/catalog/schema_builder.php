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
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_builder {
    /**
     * Build a JSON schema from an external description tree.
     *
     * @param external_description|null $description External description.
     * @return array
     */
    public static function build(?external_description $description): array {
        if ($description === null) {
            return ['type' => 'object', 'properties' => []];
        }

        return self::generate($description);
    }

    /**
     * Recursively generate a schema fragment.
     *
     * @param external_description $param Description node.
     * @return array
     */
    private static function generate(external_description $param): array {
        $type = self::schema_type($param);
        $schema = ['type' => $type];

        if ($param instanceof external_value) {
            if (!empty($param->desc)) {
                $schema['description'] = $param->desc;
            }

            if ($param->required === VALUE_REQUIRED) {
                $schema['_required'] = true;
            }

            return $schema;
        }

        if ($param instanceof external_single_structure) {
            $schema['properties'] = [];
            $requiredfields = [];

            foreach ($param->keys as $key => $subparam) {
                $subschema = self::generate($subparam);
                if (!empty($subschema['_required'])) {
                    $requiredfields[] = $key;
                }
                unset($subschema['_required']);
                $schema['properties'][$key] = $subschema;
            }

            if ($requiredfields !== []) {
                $schema['required'] = $requiredfields;
            }

            return $schema;
        }

        if ($param instanceof external_multiple_structure) {
            $itemschema = self::generate($param->content);
            unset($itemschema['_required']);
            $schema['items'] = $itemschema;
            return $schema;
        }

        return $schema;
    }

    /**
     * Map Moodle external parameter types into JSON schema types.
     *
     * @param external_description $param Description node.
     * @return string
     */
    private static function schema_type(external_description $param): string {
        if ($param instanceof external_value) {
            return match ($param->type) {
                PARAM_INT, PARAM_FLOAT => 'number',
                PARAM_BOOL => 'boolean',
                default => 'string',
            };
        }

        if ($param instanceof external_single_structure) {
            return 'object';
        }

        if ($param instanceof external_multiple_structure) {
            return 'array';
        }

        return 'object';
    }
}
