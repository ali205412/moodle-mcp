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
use context_system;
use moodle_exception;
use stdClass;
use webservice_mcp\local\auth\connector_service_manager;

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
     * Create a user holding webservice/mcp:use.
     *
     * @return stdClass
     */
    private function create_mcp_user(): stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('webservice/mcp:use', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * Test the service is created once, plugin-owned, synced, and the user is provisioned.
     */
    public function test_creates_service_and_provisions_user(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();

        $service = (new connector_service_manager())->ensure_service_for_user((int)$user->id);

        $this->assertSame((int)$service->id, (int)get_config('webservice_mcp', 'connectorserviceid'));
        $this->assertSame(1, (int)$service->enabled);
        $this->assertSame('webservice/mcp:use', $service->requiredcapability);
        $this->assertSame(1, (int)$service->restrictedusers);
        $this->assertEmpty($service->component);
        $this->assertTrue($DB->record_exists('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ]));
        $this->assertTrue($DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => 'core_webservice_get_site_info',
        ]));
    }

    /**
     * Test admin changes to the service are never overwritten and are enforced.
     */
    public function test_service_restriction_preserved(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $manager = new connector_service_manager();
        $service = $manager->ensure_service_for_user((int)$user->id);

        $DB->update_record('external_services', (object)[
            'id' => $service->id,
            'enabled' => 0,
            'requiredcapability' => 'moodle/site:config',
            'downloadfiles' => 0,
        ]);

        try {
            $manager->ensure_service_for_user((int)$user->id);
            $this->fail('A disabled service must not issue tokens.');
        } catch (moodle_exception $exception) {
            $this->assertSame('servicenotavailable', $exception->errorcode);
        }
        $manager->sync_service();

        $stored = $DB->get_record('external_services', ['id' => $service->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$stored->enabled);
        $this->assertSame('moodle/site:config', $stored->requiredcapability);
        $this->assertSame(0, (int)$stored->downloadfiles);

        $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
        $this->expectException(moodle_exception::class);
        $manager->ensure_service_for_user((int)$user->id);
    }

    /**
     * Test a user removed by an admin is not re-added, and user expiry/IP restrictions are enforced.
     */
    public function test_admin_removed_user_stays_removed_and_restrictions_enforced(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $manager = new connector_service_manager();
        $service = $manager->ensure_service_for_user((int)$user->id);

        $DB->set_field('external_services_users', 'iprestriction', '10.0.0.0/8', ['userid' => $user->id]);
        try {
            $manager->ensure_service_for_user((int)$user->id);
            $this->fail('IP restriction must be enforced.');
        } catch (moodle_exception $exception) {
            $this->assertSame('invalidiptoken', $exception->errorcode);
        }
        $this->assertSame('10.0.0.0/8', $DB->get_field('external_services_users', 'iprestriction', ['userid' => $user->id]));

        $DB->set_field('external_services_users', 'iprestriction', null, ['userid' => $user->id]);
        $DB->set_field('external_services_users', 'validuntil', time() - HOURSECS, ['userid' => $user->id]);
        try {
            $manager->ensure_service_for_user((int)$user->id);
            $this->fail('User expiry must be enforced.');
        } catch (moodle_exception $exception) {
            $this->assertSame('invalidtimedtoken', $exception->errorcode);
        }

        $DB->delete_records('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id]);
        try {
            $manager->ensure_service_for_user((int)$user->id);
            $this->fail('An admin-removed user must not be re-added.');
        } catch (moodle_exception $exception) {
            $this->assertSame('usernotallowed', $exception->errorcode);
        }
        $this->assertFalse($DB->record_exists('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ]));
    }

    /**
     * Test sync adds new functions but never re-adds functions an admin removed.
     */
    public function test_sync_adds_new_functions_but_not_admin_removed_ones(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $manager = new connector_service_manager();
        $service = $manager->ensure_service_for_user((int)$user->id);

        $DB->delete_records('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => 'core_webservice_get_site_info',
        ]);
        $DB->insert_record('external_functions', (object)[
            'name' => 'webservice_mcp_fake_new_function',
            'classname' => 'core_webservice_external',
            'methodname' => 'get_site_info',
            'component' => 'webservice_mcp',
            'capabilities' => '',
        ]);

        $manager->sync_service();

        $this->assertFalse($DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => 'core_webservice_get_site_info',
        ]));
        $this->assertTrue($DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => 'webservice_mcp_fake_new_function',
        ]));
    }

    /**
     * Test an existing service with the configured shortname that the plugin did not create is refused.
     */
    public function test_refuses_to_adopt_foreign_service(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->create_mcp_user();
        $manager = new connector_service_manager();

        $serviceid = $DB->insert_record('external_services', (object)[
            'name' => 'Admin service',
            'enabled' => 1,
            'requiredcapability' => '',
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => $manager->service_shortname(),
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ]);

        try {
            $manager->ensure_service_for_user((int)$user->id);
            $this->fail('A foreign service must not be adopted.');
        } catch (moodle_exception $exception) {
            $this->assertSame('connectorservicenotowned', $exception->errorcode);
        }
        $this->assertNull($manager->sync_service());
        $this->assertSame(0, $DB->count_records('external_services_functions', ['externalserviceid' => $serviceid]));
    }
}
