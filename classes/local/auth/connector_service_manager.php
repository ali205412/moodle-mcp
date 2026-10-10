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
use moodle_exception;
use stdClass;

/**
 * Create the plugin-owned connector service once and check user authorisation against it.
 *
 * Provisioning is create-only: once the service exists, admin changes (disabling it, restricting users,
 * removing functions, IP or expiry restrictions) are never overwritten. New external functions are added
 * by sync_service() from upgrade and a scheduled task, functions an admin removed are never re-added, and links
 * to functions Moodle no longer has are dropped.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector_service_manager {
    /** Connector service capability gate. */
    private const REQUIRED_CAPABILITY = 'webservice/mcp:use';

    /** Provisioning marker table. */
    private const PROVISION_TABLE = 'webservice_mcp_provision';

    /**
     * Return the connector service and require the user to be authorised for it.
     *
     * The user is added to the allowed-user list only if the plugin owns the service and has never
     * provisioned that user before, so an admin removal sticks.
     *
     * @param int $userid Moodle user id.
     * @return stdClass
     * @throws moodle_exception When the service or user is not authorised.
     */
    public function ensure_service_for_user(int $userid): stdClass {
        $service = $this->ensure_connector_service();
        $this->require_user_authorised($service, $userid);

        return $service;
    }

    /**
     * Return the connector service, creating (and initially syncing) it only when it does not exist.
     *
     * @return stdClass
     * @throws moodle_exception When an existing service with the configured shortname is not plugin-owned.
     */
    public function ensure_connector_service(): stdClass {
        $manager = $this->webservice_manager();
        $shortname = $this->service_shortname();
        $service = $manager->get_external_service_by_shortname($shortname);

        if (!empty($service)) {
            if (!$this->is_plugin_owned($service)) {
                throw new moodle_exception('connectorservicenotowned', 'webservice_mcp', '', $shortname);
            }
            return $service;
        }

        $serviceid = $manager->add_external_service((object)[
            'name' => substr('Moodle MCP Connector [' . $shortname . ']', 0, 200),
            'enabled' => 1,
            'requiredcapability' => self::REQUIRED_CAPABILITY,
            'restrictedusers' => 1,
            // No component: core deletes component services of plugins without db/services.php on every upgrade.
            'component' => null,
            'shortname' => $shortname,
            'downloadfiles' => 1,
            'uploadfiles' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        set_config('connectorserviceid', $serviceid, 'webservice_mcp');
        $this->sync_service();

        return $manager->get_external_service_by_id($serviceid, MUST_EXIST);
    }

    /**
     * Add newly registered external functions to the plugin-owned service and record provisioned users.
     *
     * Functions that were provisioned before and are now missing were removed by an admin and stay removed.
     * Links to functions that no longer exist in Moodle are deleted.
     *
     * @return stdClass|null The synced service, or null when there is no plugin-owned service.
     */
    public function sync_service(): ?stdClass {
        global $DB;

        $manager = $this->webservice_manager();
        $service = $manager->get_external_service_by_shortname($this->service_shortname());
        if (empty($service) || !$this->is_plugin_owned($service)) {
            return null;
        }
        $serviceid = (int)$service->id;

        $provisioned = array_fill_keys($DB->get_fieldset_select(
            self::PROVISION_TABLE,
            'itemname',
            'serviceid = ? AND itemtype = ?',
            [$serviceid, 'function']
        ), true);
        $linked = array_fill_keys($DB->get_fieldset_select(
            'external_services_functions',
            'functionname',
            'externalserviceid = ?',
            [$serviceid]
        ), true);

        $existing = $DB->get_fieldset_select('external_functions', 'name', '1 = 1', null, 'name ASC');
        foreach ($existing as $functionname) {
            if (isset($provisioned[$functionname])) {
                continue;
            }
            if (!isset($linked[$functionname])) {
                $manager->add_external_function_to_service($functionname, $serviceid);
            }
            $this->insert_provisioned($serviceid, 'function', $functionname);
        }

        // Drop links to functions Moodle no longer has (removed by a core or plugin upgrade); their provisioned
        // marker goes too, so a function that comes back is treated as new rather than as admin-removed.
        foreach (array_keys(array_diff_key($linked, array_fill_keys($existing, true))) as $functionname) {
            $manager->remove_external_function_from_service($functionname, $serviceid);
            $DB->delete_records(self::PROVISION_TABLE, [
                'serviceid' => $serviceid,
                'itemtype' => 'function',
                'itemname' => $functionname,
            ]);
        }

        $knownusers = array_fill_keys($DB->get_fieldset_select(
            self::PROVISION_TABLE,
            'itemname',
            'serviceid = ? AND itemtype = ?',
            [$serviceid, 'user']
        ), true);
        foreach ($DB->get_fieldset_select('external_services_users', 'userid', 'externalserviceid = ?', [$serviceid]) as $userid) {
            if (!isset($knownusers[(string)$userid])) {
                $this->insert_provisioned($serviceid, 'user', (string)$userid);
            }
        }

        return $service;
    }

    /**
     * Return the configured connector service shortname.
     *
     * @return string
     */
    public function service_shortname(): string {
        return (string)get_config('webservice_mcp', 'connectorserviceidentifier') ?: 'webservice_mcp_connector';
    }

    /**
     * Require the user to be allowed to obtain tokens for the service, mirroring core
     * \core_external\util::generate_token_for_current_user().
     *
     * @param stdClass $service External service record.
     * @param int $userid Moodle user id.
     * @return void
     * @throws moodle_exception
     */
    public function require_user_authorised(stdClass $service, int $userid): void {
        if (empty($service->enabled)) {
            throw new moodle_exception('servicenotavailable', 'webservice');
        }

        if (
            !empty($service->requiredcapability) &&
                !has_capability($service->requiredcapability, context_system::instance(), $userid)
        ) {
            throw new moodle_exception('missingrequiredcapability', 'webservice', '', $service->requiredcapability);
        }

        if (empty($service->restrictedusers)) {
            return;
        }

        $manager = $this->webservice_manager();
        $authoriseduser = $manager->get_ws_authorised_user((int)$service->id, $userid);
        if (
            empty($authoriseduser) && $this->is_plugin_owned($service) &&
                !$this->was_provisioned((int)$service->id, 'user', (string)$userid)
        ) {
            $manager->add_ws_authorised_user((object)[
                'externalserviceid' => (int)$service->id,
                'userid' => $userid,
                'iprestriction' => null,
                'validuntil' => null,
                'timecreated' => time(),
            ]);
            $this->insert_provisioned((int)$service->id, 'user', (string)$userid);
            return;
        }

        if (empty($authoriseduser)) {
            throw new moodle_exception('usernotallowed', 'webservice', '', $service->shortname);
        }

        if (!empty($authoriseduser->validuntil) && (int)$authoriseduser->validuntil < time()) {
            throw new moodle_exception('invalidtimedtoken', 'webservice');
        }

        if (!empty($authoriseduser->iprestriction) && !address_in_subnet(getremoteaddr(), $authoriseduser->iprestriction)) {
            throw new moodle_exception('invalidiptoken', 'webservice');
        }
    }

    /**
     * Check, without changing anything, whether an administrator may authorise a user for the service.
     *
     * IP restrictions are not checked here: they apply where the key is used, not where it is issued.
     *
     * @param stdClass $service External service record.
     * @param int $userid Target user id.
     * @param bool $mayadd Whether the issuer may add users to a restricted service.
     * @return string|null Problem code, or null when the user is (or may be made) authorised.
     */
    public function admin_authorisation_problem(stdClass $service, int $userid, bool $mayadd): ?string {
        if (empty($service->enabled)) {
            return 'servicenotavailable';
        }
        if (
            !empty($service->requiredcapability) &&
                !has_capability($service->requiredcapability, context_system::instance(), $userid)
        ) {
            return 'missingrequiredcapability';
        }
        if (empty($service->restrictedusers)) {
            return null;
        }

        $authoriseduser = $this->webservice_manager()->get_ws_authorised_user((int)$service->id, $userid);
        if (!empty($authoriseduser)) {
            return (!empty($authoriseduser->validuntil) && (int)$authoriseduser->validuntil < time()) ? 'invalidtimedtoken' : null;
        }
        $autoadd = $this->is_plugin_owned($service) && !$this->was_provisioned((int)$service->id, 'user', (string)$userid);

        return ($autoadd || $mayadd) ? null : 'usernotallowed';
    }

    /**
     * Add a user to a restricted service's allowed users on an administrator's explicit authority.
     *
     * @param stdClass $service External service record.
     * @param int $userid Target user id.
     * @return void
     */
    public function authorise_user_explicitly(stdClass $service, int $userid): void {
        $manager = $this->webservice_manager();
        if (empty($service->restrictedusers) || $manager->get_ws_authorised_user((int)$service->id, $userid)) {
            return;
        }

        $manager->add_ws_authorised_user((object)[
            'externalserviceid' => (int)$service->id,
            'userid' => $userid,
            'iprestriction' => null,
            'validuntil' => null,
            'timecreated' => time(),
        ]);
        if (!$this->was_provisioned((int)$service->id, 'user', (string)$userid)) {
            $this->insert_provisioned((int)$service->id, 'user', (string)$userid);
        }
    }

    /**
     * Whether the plugin created (owns) the given service.
     *
     * @param stdClass $service External service record.
     * @return bool
     */
    public function is_plugin_owned(stdClass $service): bool {
        return (int)$service->id === (int)get_config('webservice_mcp', 'connectorserviceid');
    }

    /**
     * Whether the plugin has provisioned an item into the service before.
     *
     * @param int $serviceid External service id.
     * @param string $itemtype user or function.
     * @param string $itemname User id or function name.
     * @return bool
     */
    private function was_provisioned(int $serviceid, string $itemtype, string $itemname): bool {
        global $DB;

        return $DB->record_exists(self::PROVISION_TABLE, [
            'serviceid' => $serviceid,
            'itemtype' => $itemtype,
            'itemname' => $itemname,
        ]);
    }

    /**
     * Record that the plugin provisioned an item into the service (caller checked it is not recorded yet).
     *
     * @param int $serviceid External service id.
     * @param string $itemtype user or function.
     * @param string $itemname User id or function name.
     * @return void
     */
    private function insert_provisioned(int $serviceid, string $itemtype, string $itemname): void {
        global $DB;

        $DB->insert_record(self::PROVISION_TABLE, (object)[
            'serviceid' => $serviceid,
            'itemtype' => $itemtype,
            'itemname' => $itemname,
            'timecreated' => time(),
        ]);
    }

    /**
     * Create the Moodle webservice manager.
     *
     * @return \webservice
     */
    private function webservice_manager(): \webservice {
        global $CFG;

        require_once($CFG->dirroot . '/webservice/lib.php');

        return new \webservice();
    }
}
