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

namespace webservice_mcp\local\files;

use context;
use context_system;
use moodle_url;
use stdClass;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\signer;

/**
 * Signed, short-lived download ('dl') and upload ('ul') tickets.
 *
 * A ticket binds the user, the connector credential, the external service and the token's context
 * restriction, so redeeming one re-checks that all of them are still valid. Tickets need no storage.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tickets {
    /** Download a single file. */
    public const KIND_FILE = 'file';

    /** Stream a course content export zip. */
    public const KIND_COURSE_CONTENT = 'course_content';

    /** Stream an assignment "download all submissions" zip. */
    public const KIND_ASSIGN_ALL = 'assign_all';

    /**
     * Issue a download ticket URL.
     *
     * @param call_context $ctx Request context.
     * @param array $claims Kind-specific claims: k plus rp/dr/fd/pv (file), courseid (course_content), cmid/groupid (assign_all).
     * @param int|null $ttl Requested lifetime in seconds.
     * @return array{url:string, expires:int}
     */
    public static function download_url(call_context $ctx, array $claims, ?int $ttl = null): array {
        self::require_service_flag($ctx->serviceid, 'downloadfiles');
        $ttl = limits::ttl('downloadticketttl', $ttl);
        $ticket = signer::sign('dl', self::base_claims($ctx) + $claims, $ttl);
        return [
            'url' => (new moodle_url('/webservice/mcp/pluginfile.php', ['ticket' => $ticket]))->out(false),
            'expires' => time() + $ttl,
        ];
    }

    /**
     * Issue an upload ticket URL.
     *
     * @param call_context $ctx Request context.
     * @param array $claims d (draft item id), fp (file path), fn (file name or null), mb (max bytes, -1 unlimited), ow (overwrite).
     * @param int|null $ttl Requested lifetime in seconds.
     * @return array{url:string, expires:int}
     */
    public static function upload_url(call_context $ctx, array $claims, ?int $ttl = null): array {
        self::require_service_flag($ctx->serviceid, 'uploadfiles');
        $ttl = limits::ttl('uploadticketttl', $ttl);
        $ticket = signer::sign('ul', self::base_claims($ctx) + $claims, $ttl);
        return [
            'url' => (new moodle_url('/webservice/mcp/upload.php', ['ticket' => $ticket]))->out(false),
            'expires' => time() + $ttl,
        ];
    }

    /**
     * Throw when the connector's external service does not allow file downloads or uploads (mirrors core).
     *
     * @param int|null $serviceid External service id; null when the call is not bound to a service.
     * @param string $flag downloadfiles or uploadfiles.
     * @return void
     * @throws transfer_exception
     */
    public static function require_service_flag(?int $serviceid, string $flag): void {
        global $DB;

        if ($serviceid === null) {
            return;
        }
        $service = $DB->get_record('external_services', ['id' => $serviceid], 'id, enabled, downloadfiles, uploadfiles');
        if (!$service || empty($service->enabled)) {
            throw new transfer_exception(403, 'servicenotavailable', 'The connector service is not available.');
        }
        if (empty($service->$flag)) {
            $what = $flag === 'uploadfiles' ? 'uploading' : 'downloading';
            throw new transfer_exception(
                403,
                'servicefiles' . $flag,
                "File {$what} is disabled for this connector service. "
                . 'A site administrator can enable it in the external service settings.'
            );
        }
    }

    /**
     * Verify a ticket and re-check everything it binds. Used by the endpoints before any file work.
     *
     * @param string $purpose dl or ul.
     * @param string $ticket Signed ticket.
     * @return array{claims:array, user:stdClass, restriction:context}
     * @throws transfer_exception
     */
    public static function redeem(string $purpose, string $ticket): array {
        global $DB, $CFG;

        $claims = $ticket === '' ? null : signer::verify($purpose, $ticket);
        if ($claims === null) {
            throw new transfer_exception(401, 'invalidticket', 'The link is invalid or has expired. Ask for a new one.');
        }

        $user = $DB->get_record('user', ['id' => (int)($claims['u'] ?? 0)]);
        if (!$user || !empty($user->deleted)) {
            throw new transfer_exception(401, 'invalidticket', 'The user of this link no longer exists.');
        }
        if (empty($user->confirmed) || !empty($user->suspended) || $user->auth === 'nologin' || isguestuser($user)) {
            throw new transfer_exception(403, 'useraccessdenied', 'This user account may not access Moodle.');
        }
        if (
            !empty($CFG->maintenance_enabled)
                && !has_capability('moodle/site:maintenanceaccess', context_system::instance(), $user)
        ) {
            throw new transfer_exception(503, 'sitemaintenance', 'The site is in maintenance mode.');
        }
        $auth = get_auth_plugin($user->auth);
        if (
            !empty($auth->config->expiration) && (int)$auth->config->expiration === 1
                && (int)$auth->password_expire($user->username) < 0
        ) {
            throw new transfer_exception(403, 'passwordexpired', 'The user password has expired.');
        }

        // Bound to the credential family: survives OAuth refresh rotation, dies with any revocation of the family.
        if (
            !isset($claims['c']) || !is_string($claims['c']) || $claims['c'] === ''
                || !(new \webservice_mcp\local\auth\credential_manager())->family_active($claims['c'])
        ) {
            throw new transfer_exception(
                401,
                'invalidticket',
                'The connector credential behind this link was revoked or expired.'
            );
        }

        if (isset($claims['s']) && $claims['s'] !== null) {
            // Same service and allowed-user checks as transport authentication (restricted users, their validuntil
            // and IP, required capability, OAuth enabled and client not revoked).
            $problem = \webservice_mcp\local\auth\service_access::problem((int)$user->id, (int)$claims['s'], $claims['c']);
            if ($problem !== null) {
                $revoked = in_array($problem, ['credentialrevoked', 'clientrevoked', 'oauthdisabled'], true);
                throw new transfer_exception($revoked ? 401 : 403, $problem, 'MCP connector access is no longer allowed: '
                    . $problem . '.');
            }
            self::require_service_flag((int)$claims['s'], $purpose === 'ul' ? 'uploadfiles' : 'downloadfiles');
        }

        $restriction = empty($claims['rc']) ? context_system::instance()
            : context::instance_by_id((int)$claims['rc'], IGNORE_MISSING);
        if (!$restriction) {
            throw new transfer_exception(401, 'invalidticket', 'The context of this link no longer exists.');
        }
        if (!has_capability('webservice/mcp:use', $restriction, $user)) {
            throw new transfer_exception(403, 'nopermissions', 'You are not allowed to use the MCP connector.');
        }

        return ['claims' => $claims, 'user' => $user, 'restriction' => $restriction];
    }

    /**
     * Claims every ticket carries.
     *
     * @param call_context $ctx Request context.
     * @return array
     */
    private static function base_claims(call_context $ctx): array {
        if ($ctx->credentialid === null || $ctx->credentialid === '') {
            // Without a credential family a link could not be revoked with the connection, so none is issued.
            throw new transfer_exception(403, 'nocredential', 'File links are only available to MCP connector connections.');
        }
        return [
            'u' => (int)$ctx->user->id,
            'c' => $ctx->credentialid,
            's' => $ctx->serviceid,
            'rc' => $ctx->restrictedcontext ? (int)$ctx->restrictedcontext->id : null,
        ];
    }
}
