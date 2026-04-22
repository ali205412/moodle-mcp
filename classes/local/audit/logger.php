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

namespace webservice_mcp\local\audit;

/**
 * Persist transport discovery and tool-call audit events.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logger {
    /** Audit table name. */
    private const TABLE = 'webservice_mcp_audit';

    /**
     * Persist one audit event and return its public audit id.
     *
     * @param array $event Audit payload.
     * @return string
     */
    public function record(array $event): string {
        global $DB;

        $auditid = (string)($event['auditid'] ?? bin2hex(random_bytes(16)));
        $record = (object)[
            'timecreated' => time(),
            'userid' => array_key_exists('userid', $event) && $event['userid'] !== null ? (int)$event['userid'] : null,
            'credentialid' => array_key_exists('credentialid', $event) && $event['credentialid'] !== null
                ? (int)$event['credentialid']
                : null,
            'contextid' => array_key_exists('contextid', $event) && $event['contextid'] !== null
                ? (int)$event['contextid']
                : null,
            'serviceid' => array_key_exists('serviceid', $event) && $event['serviceid'] !== null
                ? (int)$event['serviceid']
                : null,
            'sessionid' => $event['sessionid'] ?? null,
            'requestid' => $event['requestid'] ?? null,
            'action' => (string)($event['action'] ?? 'request'),
            'toolname' => $event['toolname'] ?? null,
            'mutating' => !empty($event['mutating']) ? 1 : 0,
            'outcome' => (string)($event['outcome'] ?? 'success'),
            'detailcode' => $event['detailcode'] ?? null,
            'auditid' => $auditid,
        ];

        $DB->insert_record(self::TABLE, $record);
        return $auditid;
    }
}
