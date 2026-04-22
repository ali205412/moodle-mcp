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

/**
 * Derive structured risk metadata for harvested tools.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class risk_analyzer {
    /**
     * Analyze one harvested catalog entry.
     *
     * @param array $entry Catalog entry.
     * @return array
     */
    public function analyze(array $entry): array {
        $signals = [];
        $levelscore = ($entry['mutability'] ?? 'read') === 'write' ? 2 : 1;
        $capabilitymeta = [];
        $destructive = (bool)($entry['annotations']['destructiveHint'] ?? false);

        if ($destructive) {
            $signals[] = 'destructive_operation';
            $levelscore = max($levelscore, 3);
        }

        foreach (($entry['capabilities'] ?? []) as $capability) {
            $capinfo = \get_capability_info($capability, false);
            if (!$capinfo) {
                continue;
            }

            $capabilitymeta[] = [
                'name' => $capability,
                'captype' => (string)$capinfo->captype,
                'contextlevel' => (int)$capinfo->contextlevel,
                'riskbitmask' => (int)$capinfo->riskbitmask,
            ];

            if ($capinfo->captype === 'write') {
                $signals[] = 'write_capability';
                $levelscore = max($levelscore, 2);
            }

            if (!empty($capinfo->riskbitmask & RISK_PERSONAL)) {
                $signals[] = 'personal_data';
                $levelscore = max($levelscore, 2);
            }

            if (!empty($capinfo->riskbitmask & RISK_SPAM)) {
                $signals[] = 'spam';
                $levelscore = max($levelscore, 2);
            }

            if (!empty($capinfo->riskbitmask & RISK_XSS)) {
                $signals[] = 'xss';
                $levelscore = max($levelscore, 3);
            }

            if (!empty($capinfo->riskbitmask & RISK_DATALOSS)) {
                $signals[] = 'data_loss';
                $levelscore = max($levelscore, 3);
            }

            if (!empty($capinfo->riskbitmask & RISK_CONFIG)) {
                $signals[] = 'site_configuration';
                $levelscore = max($levelscore, 4);
            }

            if (!empty($capinfo->riskbitmask & RISK_MANAGETRUST)) {
                $signals[] = 'trust_boundary';
                $levelscore = max($levelscore, 3);
            }

            if (str_starts_with($capability, 'moodle/site:')) {
                $signals[] = 'site_scope';
                $levelscore = max($levelscore, 4);
            }
        }

        $level = $this->level_from_score($levelscore);
        $confirmationrequired = $destructive
            || ($entry['mutability'] ?? 'read') === 'write'
            || in_array($level, ['high', 'critical'], true);

        return [
            'level' => $level,
            'confirmationRequired' => $confirmationrequired,
            'signals' => array_values(array_unique($signals)),
            'destructive' => $destructive,
            'capabilities' => $capabilitymeta,
        ];
    }

    /**
     * Map a numeric score to a stable risk level.
     *
     * @param int $score Risk score.
     * @return string
     */
    private function level_from_score(int $score): string {
        return match (true) {
            $score >= 4 => 'critical',
            $score >= 3 => 'high',
            $score >= 2 => 'medium',
            default => 'low',
        };
    }
}
