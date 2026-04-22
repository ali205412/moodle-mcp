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
use context_system;
use core_user;
use moodle_exception;
use required_capability_exception;
use stdClass;

/**
 * Browser/session bootstrap service for connector access.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bootstrap_service {
    /** @var credential_manager */
    private credential_manager $credentialmanager;

    /** @var connector_service_manager */
    private connector_service_manager $connectormanager;

    /**
     * Constructor.
     *
     * @param credential_manager|null $credentialmanager Optional credential manager override.
     * @param connector_service_manager|null $connectormanager Optional connector service manager override.
     */
    public function __construct(
        ?credential_manager $credentialmanager = null,
        ?connector_service_manager $connectormanager = null
    ) {
        $this->credentialmanager = $credentialmanager ?? new credential_manager();
        $this->connectormanager = $connectormanager ?? new connector_service_manager();
    }

    /**
     * Issue bootstrap access for the current user.
     *
     * @param context|null $context Optional restricted context.
     * @return stdClass
     */
    public function issue_bootstrap_for_current_user(?context $context = null): stdClass {
        global $USER;

        $context ??= context_system::instance();

        $this->require_bootstrap_access($context);
        $service = $this->connectormanager->ensure_service_for_user((int)$USER->id);

        return $this->credentialmanager->issue_bootstrap_credential(
            $service,
            (int)$USER->id,
            $context,
            ['usermodified' => (int)$USER->id]
        );
    }

    /**
     * Ensure the current user may bootstrap connector access.
     *
     * @param context|null $context Optional restricted context.
     * @return void
     */
    public function require_bootstrap_access(?context $context = null): void {
        global $USER;

        $context ??= context_system::instance();

        core_user::require_active_user($USER);

        if (!has_capability('webservice/mcp:use', $context)) {
            throw new required_capability_exception(
                $context,
                'webservice/mcp:use',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Build a response payload for the issued credential.
     *
     * @param stdClass $credential Connector credential record.
     * @return array
     */
    public function build_bootstrap_payload(stdClass $credential): array {
        return [
            'token' => $credential->token,
            'serviceidentifier' => $credential->serviceidentifier,
            'contextid' => (int)$credential->contextid,
            'tokentype' => (int)$credential->tokentype,
            'validuntil' => empty($credential->validuntil) ? null : (int)$credential->validuntil,
            'revoked' => (bool)$credential->revoked,
        ];
    }

    /**
     * Determine whether JSON output was requested.
     *
     * @param string $format Explicit format parameter.
     * @return bool
     */
    public function wants_json_response(string $format = ''): bool {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return $format === 'json' || stripos($accept, 'application/json') !== false;
    }

    /**
     * Get the connector service definition used for Phase 1 bootstrap.
     *
     * @return stdClass
     */
    public function get_connector_service_definition(): stdClass {
        return (object)[
            'id' => 0,
            'shortname' => (string)get_config('webservice_mcp', 'connectorserviceidentifier') ?: 'webservice_mcp_connector',
            'name' => 'Moodle MCP Connector',
        ];
    }

    /**
     * Ensure durable grants are currently allowed.
     *
     * @return void
     */
    public function require_durable_grants_enabled(): void {
        if (!get_config('webservice_mcp', 'allowdurablegrants')) {
            throw new moodle_exception('invalidparameter');
        }
    }
}
