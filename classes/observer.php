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

namespace webservice_mcp;

use core\event\base;
use webservice_mcp\local\auth\credential_manager;

/**
 * Revoke connector credentials when the account they act for changes.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A password change by an administrator, a forgotten-password reset, or any change when the site deletes
     * web service tokens on password change ends every connector grant of the user.
     *
     * @param base $event user_password_updated event.
     * @return void
     */
    public static function user_password_updated(base $event): void {
        global $CFG;

        // Mirror core's web service token handling: the event also fires when login merely re-hashes a stored
        // password, which must not disconnect anyone. Revoke when the site deletes tokens on password change,
        // after a forgotten-password reset, or when someone else (an administrator) set the password.
        $resetbyother = (int)$event->userid > 0 && (int)$event->userid !== (int)$event->relateduserid;
        if (!empty($CFG->passwordchangetokendeletion) || !empty($event->other['forgottenreset']) || $resetbyother) {
            self::revoke_user((int)$event->relateduserid, (int)$event->userid);
        }
    }

    /**
     * Revoke when the updated account can no longer log in (suspended, nologin, unconfirmed, deleted).
     *
     * @param base $event user_updated event.
     * @return void
     */
    public static function user_updated(base $event): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $event->relateduserid]);
        if (
            !$user || !empty($user->deleted) || !empty($user->suspended) || empty($user->confirmed) ||
                $user->auth === 'nologin' || !is_enabled_auth($user->auth)
        ) {
            self::revoke_user((int)$event->relateduserid, (int)$event->userid);
        }
    }

    /**
     * A deleted user keeps no credentials or pending authorization codes.
     *
     * @param base $event user_deleted event.
     * @return void
     */
    public static function user_deleted(base $event): void {
        global $DB;

        $DB->delete_records('webservice_mcp_credential', ['userid' => $event->objectid]);
        $DB->delete_records('webservice_mcp_oauth_code', ['userid' => $event->objectid]);
        $DB->delete_records('webservice_mcp_preapproval', ['userid' => $event->objectid]);
        $DB->delete_records('webservice_mcp_link', ['userid' => $event->objectid]);
    }

    /**
     * End the UI bridge session of a credential family that has no active credential left.
     *
     * @param base $event credential_revoked event.
     * @return void
     */
    public static function credential_revoked(base $event): void {
        global $DB;

        $credential = $DB->get_record('webservice_mcp_credential', ['id' => $event->objectid]);
        if ($credential) {
            \webservice_mcp\local\ui\session_bridge::end_session_for_credential($credential);
        }
    }

    /**
     * Revoke all credentials and burn pending authorization codes of a user.
     *
     * @param int $userid Affected user.
     * @param int $actorid User performing the change.
     * @return void
     */
    private static function revoke_user(int $userid, int $actorid): void {
        global $DB;

        if ($userid <= 0) {
            return;
        }
        (new credential_manager())->revoke_all_for_user($userid, $actorid > 0 ? $actorid : $userid);
        $DB->set_field('webservice_mcp_oauth_code', 'used', 1, ['userid' => $userid, 'used' => 0]);
    }
}
