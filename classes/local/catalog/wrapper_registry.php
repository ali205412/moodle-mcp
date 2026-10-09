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

/**
 * Registry of plugin-owned wrapper tools for future coverage phases.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wrapper_registry {
    /** @var array */
    private array $descriptors;

    /**
     * Constructor.
     *
     * @param array $descriptors Optional override descriptors for tests or later phases.
     */
    public function __construct(array $descriptors = []) {
        $this->descriptors = $descriptors;
    }

    /**
     * Return all registered wrapper descriptors.
     *
     * @return array
     */
    public function all(): array {
        return array_merge(workflow_descriptors::all(), $this->descriptors);
    }

    /**
     * Return workflow descriptors that include the given tool.
     *
     * @param string $toolname Tool name.
     * @return array
     */
    public function for_tool(string $toolname): array {
        $matches = [];
        foreach ($this->all() as $descriptor) {
            if (!in_array($toolname, $descriptor['tools'] ?? [], true)) {
                continue;
            }

            $matches[] = [
                'name' => $descriptor['name'],
                'type' => $descriptor['type'] ?? 'workflow',
                'domain' => $descriptor['domain'] ?? 'core',
                'component' => $descriptor['component'] ?? '',
                'step' => array_search($toolname, $descriptor['tools'], true) + 1,
                'steps' => $descriptor['tools'],
            ];
        }

        return $matches;
    }

    /**
     * Return all workflow descriptors for a given component.
     *
     * @param string $component Frankenstyle component.
     * @return array
     */
    public function for_component(string $component): array {
        $matches = [];
        foreach ($this->all() as $descriptor) {
            if (($descriptor['component'] ?? '') !== $component) {
                continue;
            }
            $matches[] = $descriptor;
        }

        return $matches;
    }
}
