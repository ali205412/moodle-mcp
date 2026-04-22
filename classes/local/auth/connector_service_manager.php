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

use stdClass;

/**
 * Ensure the plugin-owned connector service exists and stays synced.
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

    /** Component owner recorded on the external service row. */
    private const COMPONENT = 'webservice_mcp';

    /**
     * Ensure the connector service exists, is configured correctly, and allows the user.
     *
     * @param int $userid Moodle user id.
     * @return stdClass
     */
    public function ensure_service_for_user(int $userid): stdClass {
        $service = $this->ensure_connector_service();
        $this->ensure_authorised_user($service, $userid);

        return $service;
    }

    /**
     * Ensure the connector service exists and is synced to all registered externals.
     *
     * @return stdClass
     */
    public function ensure_connector_service(): stdClass {
        $manager = $this->webservice_manager();
        $shortname = $this->service_shortname();
        $service = $manager->get_external_service_by_shortname($shortname);
        $desired = $this->service_defaults($shortname);

        if (empty($service)) {
            $serviceid = $manager->add_external_service((object)$desired);
            $service = $manager->get_external_service_by_id($serviceid, MUST_EXIST);
        } else {
            $updates = (object)['id' => (int)$service->id];
            $dirty = false;

            foreach ($desired as $field => $value) {
                if (($service->{$field} ?? null) !== $value) {
                    $updates->{$field} = $value;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $manager->update_external_service($updates);
                $service = $manager->get_external_service_by_id((int)$service->id, MUST_EXIST);
            }
        }

        $this->sync_service_functions((int)$service->id, $manager);

        return $manager->get_external_service_by_id((int)$service->id, MUST_EXIST);
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
     * Build the desired service field values.
     *
     * @param string $shortname Configured connector service shortname.
     * @return array
     */
    private function service_defaults(string $shortname): array {
        return [
            'name' => $this->service_name($shortname),
            'enabled' => 1,
            'requiredcapability' => self::REQUIRED_CAPABILITY,
            'restrictedusers' => 1,
            'component' => self::COMPONENT,
            'shortname' => $shortname,
            'downloadfiles' => 1,
            'uploadfiles' => 1,
        ];
    }

    /**
     * Generate a stable, unique-ish service name derived from the configured shortname.
     *
     * @param string $shortname Configured connector service shortname.
     * @return string
     */
    private function service_name(string $shortname): string {
        $name = 'Moodle MCP Connector [' . $shortname . ']';
        return substr($name, 0, 200);
    }

    /**
     * Ensure the supplied user is present in the service's allowed-user table.
     *
     * @param stdClass $service External service record.
     * @param int $userid Moodle user id.
     * @return void
     */
    private function ensure_authorised_user(stdClass $service, int $userid): void {
        $manager = $this->webservice_manager();
        $authoriseduser = $manager->get_ws_authorised_user((int)$service->id, $userid);

        if (empty($authoriseduser)) {
            $manager->add_ws_authorised_user((object)[
                'externalserviceid' => (int)$service->id,
                'userid' => $userid,
                'iprestriction' => null,
                'validuntil' => null,
            ]);
            return;
        }

        $updates = (object)['id' => (int)$authoriseduser->serviceuserid];
        $dirty = false;

        if (!empty($authoriseduser->iprestriction)) {
            $updates->iprestriction = null;
            $dirty = true;
        }

        if (!empty($authoriseduser->validuntil)) {
            $updates->validuntil = null;
            $dirty = true;
        }

        if ($dirty) {
            $manager->update_ws_authorised_user($updates);
        }
    }

    /**
     * Sync service membership to the currently registered external function table.
     *
     * @param int $serviceid External service id.
     * @param \webservice $manager Moodle webservice manager.
     * @return void
     */
    private function sync_service_functions(int $serviceid, \webservice $manager): void {
        global $DB;

        $allfunctions = $DB->get_fieldset_select('external_functions', 'name', '1 = 1', null, 'name ASC');
        $linkedfunctions = $DB->get_fieldset_select(
            'external_services_functions',
            'functionname',
            'externalserviceid = ?',
            [$serviceid],
            'functionname ASC'
        );

        $allindex = array_fill_keys($allfunctions, true);
        $linkedindex = array_fill_keys($linkedfunctions, true);

        foreach ($allfunctions as $functionname) {
            if (!isset($linkedindex[$functionname])) {
                $manager->add_external_function_to_service($functionname, $serviceid);
            }
        }

        foreach ($linkedfunctions as $functionname) {
            if (!isset($allindex[$functionname])) {
                $manager->remove_external_function_from_service($functionname, $serviceid);
            }
        }
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
