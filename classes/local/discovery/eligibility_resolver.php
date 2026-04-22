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

namespace webservice_mcp\local\discovery;

use context;
use context_system;
use stdClass;

/**
 * Resolve which harvested tools can be shown safely to the current user.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eligibility_resolver {
    /**
     * Evaluate discovery-time visibility and hints for one entry.
     *
     * @param array $entry Catalog entry.
     * @param context|null $restrictedcontext Restricted context for the current transport/user.
     * @param stdClass|null $user Current user.
     * @param array $snapshotentries Full harvested catalog entries keyed by name.
     * @param array $options Extra evaluation options.
     * @return array
     */
    public function evaluate(
        array $entry,
        ?context $restrictedcontext = null,
        ?stdClass $user = null,
        array $snapshotentries = [],
        array $options = []
    ): array {
        $restrictedcontext ??= context_system::instance();
        $user ??= $GLOBALS['USER'] ?? null;

        $visible = true;
        $reasons = [];
        $resolvedcapabilities = [];
        $deferredcapabilities = [];

        if (($entry['transport']['loginrequired'] ?? true) && empty($user?->id)) {
            $visible = false;
            $reasons[] = [
                'code' => 'login_required',
                'message' => 'This tool requires an authenticated Moodle user session.',
            ];
        }

        $risk = $options['risk'] ?? ['level' => 'low'];
        $showhighrisktools = $this->show_high_risk_tools($options['site_policy'] ?? []);
        if (!$showhighrisktools && in_array($risk['level'], ['high', 'critical'], true)) {
            $visible = false;
            $reasons[] = [
                'code' => 'site_policy_hidden',
                'message' => 'The current site policy hides high-risk tools from discovery.',
            ];
        }

        foreach (($entry['capabilities'] ?? []) as $capability) {
            $capinfo = \get_capability_info($capability, false);
            if (!$capinfo) {
                $deferredcapabilities[] = $capability;
                continue;
            }

            if (!$this->can_safely_evaluate_capability($restrictedcontext, (int)$capinfo->contextlevel)) {
                $deferredcapabilities[] = $capability;
                continue;
            }

            $resolvedcapabilities[] = $capability;
            if ($user !== null && !\has_capability($capability, $restrictedcontext, $user)) {
                $visible = false;
                $reasons[] = [
                    'code' => 'missing_capability',
                    'message' => 'The current user does not have the required capability for this tool in the restricted context.',
                    'capability' => $capability,
                ];
            }
        }

        $accesstools = $this->access_information_tools($entry, $snapshotentries);
        $calltimechecks = ['context'];
        if ($deferredcapabilities !== []) {
            $calltimechecks[] = 'capability';
        }
        if ($accesstools !== []) {
            $calltimechecks = array_merge($calltimechecks, $this->access_boundaries_for($entry));
        }

        return [
            'visible' => $visible,
            'status' => $visible ? 'visible' : 'hidden',
            'reasons' => $reasons,
            'callTimeChecks' => array_values(array_unique($calltimechecks)),
            'resolvedCapabilities' => $resolvedcapabilities,
            'deferredCapabilities' => array_values(array_unique($deferredcapabilities)),
            'accessInformationTools' => $accesstools,
            'connectorMode' => (string)($options['connector_mode'] ?? 'default'),
            'sitePolicy' => [
                'showHighRiskTools' => $showhighrisktools,
            ],
        ];
    }

    /**
     * Determine whether the current context can safely evaluate the capability now.
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

    /**
     * Find same-component access-information tools that can help the client refine eligibility.
     *
     * @param array $entry Catalog entry.
     * @param array $snapshotentries Full catalog entries.
     * @return array
     */
    private function access_information_tools(array $entry, array $snapshotentries): array {
        $tools = [];

        foreach ($snapshotentries as $candidate) {
            if (($candidate['component'] ?? '') !== ($entry['component'] ?? '')) {
                continue;
            }

            $name = (string)($candidate['name'] ?? '');
            if ($name === '' || $name === ($entry['name'] ?? '')) {
                continue;
            }

            if (preg_match('/_get_.*access_information$/', $name) === 1 ||
                    preg_match('/_get_access_information$/', $name) === 1) {
                $tools[] = $name;
            }
        }

        sort($tools);
        return array_values(array_unique($tools));
    }

    /**
     * Return boundary hints associated with component access-information helpers.
     *
     * @param array $entry Catalog entry.
     * @return array
     */
    private function access_boundaries_for(array $entry): array {
        $boundaries = ['role', 'enrolment', 'availability', 'visibility'];

        if (str_starts_with((string)($entry['component'] ?? ''), 'mod_')) {
            $boundaries[] = 'group';
            $boundaries[] = 'ownership';
        } else if (in_array((string)($entry['domain'] ?? ''), ['core', 'activity'], true)) {
            $boundaries[] = 'ownership';
        }

        return $boundaries;
    }

    /**
     * Resolve the high-risk discovery site policy.
     *
     * @param array $sitepolicy Optional override policy.
     * @return bool
     */
    private function show_high_risk_tools(array $sitepolicy): bool {
        if (array_key_exists('showHighRiskTools', $sitepolicy)) {
            return (bool)$sitepolicy['showHighRiskTools'];
        }

        $configured = \get_config('webservice_mcp', 'showhighrisktools');
        return $configured === false ? true : (bool)$configured;
    }
}
