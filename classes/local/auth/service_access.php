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

namespace webservice_mcp\local\auth;

use context_system;

/**
 * One shared re-check of connector access, used by the transport, file tickets, and cron tasks.
 *
 * Mirrors the transport's service and user checks (core webservice_server authentication) so access granted
 * earlier (a ticket, a queued task) ends as soon as an administrator or the user withdraws it.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_access {
    /**
     * Return why access is no longer allowed, or null when it is.
     *
     * Checks: service exists and is enabled; user exists, is not deleted or suspended, is confirmed, and is not
     * nologin; the service's required capability; for restricted services the allowed-user row, its validuntil
     * and (optionally) its IP restriction; and, with a family key, that the family still has an active credential
     * and, for OAuth credentials, that OAuth is enabled and the client is not revoked.
     *
     * @param int $userid User id.
     * @param int $serviceid External service id.
     * @param string|null $familykey Credential family key, or null.
     * @param bool $checkip Whether to enforce the allowed-user IP restriction.
     * @return string|null Problem code: servicenotavailable, userinactive, missingrequiredcapability, usernotallowed,
     *     invalidtimedtoken, invalidiptoken, credentialrevoked, oauthdisabled, clientrevoked.
     */
    public static function problem(int $userid, int $serviceid, ?string $familykey = null, bool $checkip = true): ?string {
        global $DB;

        $service = $DB->get_record('external_services', ['id' => $serviceid]);
        if (!$service || empty($service->enabled)) {
            return 'servicenotavailable';
        }

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user || !empty($user->deleted) || !empty($user->suspended) || empty($user->confirmed) || $user->auth === 'nologin') {
            return 'userinactive';
        }

        $requiredcapability = (string)($service->requiredcapability ?? '');
        if ($requiredcapability !== '' && !has_capability($requiredcapability, context_system::instance(), $user)) {
            return 'missingrequiredcapability';
        }

        if (!empty($service->restrictedusers)) {
            $allowed = $DB->get_record('external_services_users', ['externalserviceid' => $serviceid, 'userid' => $userid]);
            if (!$allowed) {
                return 'usernotallowed';
            }
            if (!empty($allowed->validuntil) && (int)$allowed->validuntil < time()) {
                return 'invalidtimedtoken';
            }
            if ($checkip && !empty($allowed->iprestriction) && !address_in_subnet(getremoteaddr(), $allowed->iprestriction)) {
                return 'invalidiptoken';
            }
        }

        if ($familykey !== null && $familykey !== '') {
            $credential = (new credential_manager())->find_active_in_family($familykey);
            if (!$credential || (int)$credential->userid !== $userid) {
                return 'credentialrevoked';
            }
            if (!empty($credential->oauthclientid)) {
                if (!get_config('webservice_mcp', 'oauthenabled')) {
                    return 'oauthdisabled';
                }
                $clientactive = $DB->record_exists('webservice_mcp_oauth_client', [
                    'clientid' => $credential->oauthclientid,
                    'revoked' => 0,
                ]);
                if (!$clientactive) {
                    return 'clientrevoked';
                }
            }
        }

        return null;
    }

    /**
     * Throw when access is no longer allowed.
     *
     * @param int $userid User id.
     * @param int $serviceid External service id.
     * @param string|null $familykey Credential family key, or null.
     * @param bool $checkip Whether to enforce the allowed-user IP restriction.
     * @return void
     * @throws \webservice_access_exception
     */
    public static function assert(int $userid, int $serviceid, ?string $familykey = null, bool $checkip = true): void {
        $problem = self::problem($userid, $serviceid, $familykey, $checkip);
        if ($problem !== null) {
            global $CFG;
            require_once($CFG->dirroot . '/webservice/lib.php');
            throw new \webservice_access_exception('MCP connector access is no longer allowed: ' . $problem);
        }
    }
}
