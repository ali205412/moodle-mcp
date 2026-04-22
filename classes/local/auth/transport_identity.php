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

use context;
use stdClass;

/**
 * Resolve connector credentials into transport-side identity context.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transport_identity {
    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /**
     * Constructor.
     *
     * @param credential_manager|null $credentialmanager Optional credential manager override.
     */
    public function __construct(?credential_manager $credentialmanager = null) {
        $this->credentialmanager = $credentialmanager ?? new credential_manager();
    }

    /**
     * Resolve a connector token into runtime identity.
     *
     * @param string $token Connector token.
     * @return stdClass|null
     */
    public function resolve(string $token): ?stdClass {
        global $DB;

        $credential = $this->credentialmanager->resolve_credential($token);
        if (!$credential) {
            return null;
        }

        if ((int)$credential->tokentype === credential_manager::TOKEN_TYPE_REFRESH) {
            return null;
        }

        $user = $DB->get_record('user', ['id' => $credential->userid], '*', MUST_EXIST);
        $context = context::instance_by_id((int)$credential->contextid);

        return (object)[
            'user' => $user,
            'restrictedcontext' => $context,
            'restrictedservice' => $credential->serviceidentifier,
            'tokentype' => (int)$credential->tokentype,
            'sid' => $credential->sid,
            'scope' => (string)($credential->scope ?? ''),
            'resourceuri' => !empty($credential->resourceuri) ? (string)$credential->resourceuri : null,
            'oauthclientid' => !empty($credential->oauthclientid) ? (string)$credential->oauthclientid : null,
            'credential' => $credential,
        ];
    }
}
