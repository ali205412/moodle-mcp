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

use context;
use stdClass;

/**
 * Immutable definition of a connector-owned wrapper tool.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class definition {
    /**
     * Constructor.
     *
     * @param string $name Wrapper tool name.
     * @param string $component Owning component.
     * @param string $domain Domain label.
     * @param string $description Tool description.
     * @param array $requiredcapabilities Capabilities required to expose the tool.
     * @param array $inputschema Input schema.
     * @param array $outputschema Output schema.
     */
    public function __construct(
        private string $name,
        private string $component,
        private string $domain,
        private string $description,
        private array $requiredcapabilities = [],
        private array $inputschema = ['type' => 'object', 'properties' => []],
        private array $outputschema = ['type' => 'object', 'properties' => []],
    ) {
    }

    /**
     * Return a serializable description of the wrapper definition.
     *
     * @return array
     */
    public function describe(): array {
        return [
            'name' => $this->name,
            'component' => $this->component,
            'domain' => $this->domain,
            'description' => $this->description,
            'requiredCapabilities' => $this->requiredcapabilities,
            'inputSchema' => $this->inputschema,
            'outputSchema' => $this->outputschema,
        ];
    }

    /**
     * Return the wrapper tool name.
     *
     * @return string
     */
    public function get_name(): string {
        return $this->name;
    }

    /**
     * Determine whether the wrapper can be shown in discovery now.
     *
     * @param context $restrictedcontext Current restricted context.
     * @param stdClass|null $user Current user.
     * @return bool
     */
    public function can_discover(context $restrictedcontext, ?stdClass $user = null): bool {
        foreach ($this->requiredcapabilities as $capability) {
            $capinfo = \get_capability_info($capability, false);
            if ($capinfo && !$this->can_safely_evaluate_capability($restrictedcontext, (int)$capinfo->contextlevel)) {
                continue;
            }

            if (!$user || !\has_capability($capability, $restrictedcontext, $user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the capability can be evaluated safely in the restricted context.
     *
     * @param context $restrictedcontext Restricted discovery context.
     * @param int $capcontextlevel Capability context level.
     * @return bool
     */
    private function can_safely_evaluate_capability(context $restrictedcontext, int $capcontextlevel): bool {
        if ($capcontextlevel <= 0) {
            return false;
        }

        if ($capcontextlevel === CONTEXT_SYSTEM) {
            return true;
        }

        if ($capcontextlevel === $restrictedcontext->contextlevel) {
            return true;
        }

        return match ($restrictedcontext->contextlevel) {
            CONTEXT_COURSECAT => $capcontextlevel === CONTEXT_SYSTEM,
            CONTEXT_COURSE => in_array($capcontextlevel, [CONTEXT_COURSECAT, CONTEXT_SYSTEM], true),
            CONTEXT_MODULE => in_array($capcontextlevel, [CONTEXT_COURSE, CONTEXT_COURSECAT, CONTEXT_SYSTEM], true),
            CONTEXT_BLOCK => in_array($capcontextlevel, [CONTEXT_MODULE, CONTEXT_COURSE, CONTEXT_COURSECAT, CONTEXT_SYSTEM], true),
            default => false,
        };
    }
}
