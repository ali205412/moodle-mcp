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

namespace webservice_mcp;

use advanced_testcase;
use webservice_mcp\local\auth\connector_service_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for connector-owned external service provisioning.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\auth\connector_service_manager
 */
final class connector_service_manager_test extends advanced_testcase {
    /**
     * Test service sync updates settings and removes stale function links.
     */
    public function test_ensure_service_for_user_updates_existing_service_and_syncs_functions(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $manager = new connector_service_manager();

        $serviceid = $DB->insert_record('external_services', (object)[
            'name' => 'Legacy connector service',
            'enabled' => 0,
            'requiredcapability' => '',
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => $manager->service_shortname(),
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ]);
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $serviceid,
            'functionname' => 'fake_plugin_missing_function',
        ]);
        $DB->insert_record('external_services_users', (object)[
            'externalserviceid' => $serviceid,
            'userid' => $user->id,
            'iprestriction' => '10.0.0.0/8',
            'validuntil' => time() - HOURSECS,
            'timecreated' => time(),
        ]);

        $service = $manager->ensure_service_for_user($user->id);

        $alloweduser = $DB->get_record('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ], '*', MUST_EXIST);

        $this->assertSame((int)$serviceid, (int)$service->id);
        $this->assertSame(1, (int)$service->enabled);
        $this->assertSame('webservice/mcp:use', $service->requiredcapability);
        $this->assertSame(1, (int)$service->restrictedusers);
        $this->assertSame(1, (int)$service->downloadfiles);
        $this->assertSame(1, (int)$service->uploadfiles);
        $this->assertSame('webservice_mcp', (string)$service->component);
        $this->assertEmpty($alloweduser->iprestriction);
        $this->assertEmpty($alloweduser->validuntil);
        $this->assertFalse($DB->record_exists('external_services_functions', [
            'externalserviceid' => $serviceid,
            'functionname' => 'fake_plugin_missing_function',
        ]));
        $this->assertTrue($DB->record_exists('external_services_functions', [
            'externalserviceid' => $serviceid,
            'functionname' => 'core_webservice_get_site_info',
        ]));
    }
}
