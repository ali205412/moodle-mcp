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
use stdClass;

/**
 * Operator-facing service for inspecting and revoking connector credentials.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class credential_admin_service {
    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /**
     * Constructor.
     *
     * @param credential_manager|null $credentialmanager Optional manager override.
     */
    public function __construct(?credential_manager $credentialmanager = null) {
        $this->credentialmanager = $credentialmanager ?? new credential_manager();
    }

    /**
     * List active credentials for a user.
     *
     * @param int $userid Moodle user id.
     * @return array
     */
    public function list_user_credentials(int $userid): array {
        require_capability('webservice/mcp:manageconnectors', context_system::instance());
        return $this->credentialmanager->list_credentials_for_user($userid);
    }

    /**
     * Revoke a connector credential by token.
     *
     * @param string $token Connector token.
     * @return bool
     */
    public function revoke_token(string $token): bool {
        global $USER;

        require_capability('webservice/mcp:manageconnectors', context_system::instance());
        return $this->credentialmanager->revoke_credential($token, (int)$USER->id);
    }

    /**
     * Describe a connector credential for inspection.
     *
     * @param stdClass $credential Credential record.
     * @return array
     */
    public function describe_credential(stdClass $credential): array {
        return [
            'id' => (int)$credential->id,
            'userid' => (int)$credential->userid,
            'name' => $credential->name,
            'serviceidentifier' => $credential->serviceidentifier,
            'contextid' => (int)$credential->contextid,
            'tokentype' => (int)$credential->tokentype,
            'validuntil' => empty($credential->validuntil) ? null : (int)$credential->validuntil,
            'lastaccess' => empty($credential->lastaccess) ? null : (int)$credential->lastaccess,
            'revoked' => (bool)$credential->revoked,
        ];
    }
}
