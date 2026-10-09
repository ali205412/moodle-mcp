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

namespace webservice_mcp\task;

/**
 * Purge expired/revoked credentials (incl. admin keys), spent codes, expired pre-approvals, old audit rows, unused DCR clients.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /** Default days revoked or expired credentials are kept (so refresh replays are still detected). */
    private const CREDENTIAL_RETENTION_DAYS = 30;

    /** Default days an unused dynamically registered client is kept. */
    private const UNUSED_CLIENT_DAYS = 30;

    /**
     * Read a day-count setting, falling back to a default when unset or not positive.
     *
     * @param string $name Setting name.
     * @param int $default Default days.
     * @return int
     */
    private static function days(string $name, int $default): int {
        $days = (int)get_config('webservice_mcp', $name);
        return $days > 0 ? $days : $default;
    }

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:cleanup', 'webservice_mcp');
    }

    /**
     * Run the cleanup.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        \webservice_mcp\local\mcp\tasks::purge_expired();

        $now = time();
        $credentialcutoff = $now - self::days('credentialretentiondays', self::CREDENTIAL_RETENTION_DAYS) * DAYSECS;
        $DB->delete_records_select(
            'webservice_mcp_credential',
            '(revoked = 1 AND timemodified < :cutoff1) OR (validuntil > 0 AND validuntil < :cutoff2)',
            ['cutoff1' => $credentialcutoff, 'cutoff2' => $credentialcutoff]
        );

        $DB->delete_records_select('webservice_mcp_oauth_code', 'expiresat < :cutoff', ['cutoff' => $now - DAYSECS]);
        $DB->delete_records_select('webservice_mcp_preapproval', 'expiry > 0 AND expiry < :now', ['now' => $now]);
        $DB->delete_records_select('webservice_mcp_jti', 'expiresat < :cutoff', ['cutoff' => $now - HOURSECS]);
        $DB->delete_records_select('webservice_mcp_ratelimit', 'timecreated < :cutoff', ['cutoff' => $now - 2 * HOURSECS]);
        $DB->delete_records_select('webservice_mcp_link', 'expiresat < :now', ['now' => $now]);

        // Asynchronous file exports (zips and their state) older than 24 hours.
        \webservice_mcp\local\files\export_service::purge($now);

        $auditdays = get_config('webservice_mcp', 'auditretentiondays');
        $auditdays = $auditdays === false ? 90 : (int)$auditdays;
        if ($auditdays > 0) {
            $DB->delete_records_select('webservice_mcp_audit', 'timecreated < :cutoff', ['cutoff' => $now - $auditdays * DAYSECS]);
        }

        $DB->delete_records_select(
            'webservice_mcp_oauth_client',
            'isdynamic = 1 AND timecreated < :cutoff
             AND NOT EXISTS (SELECT 1 FROM {webservice_mcp_credential} c
                              WHERE c.oauthclientid = {webservice_mcp_oauth_client}.clientid)
             AND NOT EXISTS (SELECT 1 FROM {webservice_mcp_oauth_code} oc
                              WHERE oc.clientid = {webservice_mcp_oauth_client}.clientid)',
            ['cutoff' => $now - self::days('dcrclientretentiondays', self::UNUSED_CLIENT_DAYS) * DAYSECS]
        );
    }
}
