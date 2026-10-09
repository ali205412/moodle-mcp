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
use webservice_mcp\local\catalog\catalog_builder;
use webservice_mcp\local\discovery\visibility_cache;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/counting_eligibility_resolver.php');

/**
 * Tests for the per-user visibility cache.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\discovery\visibility_cache
 */
final class visibility_cache_test extends advanced_testcase {
    /**
     * Create an enabled service with the given functions.
     *
     * @param array $functionnames Function names.
     * @return int
     */
    private function create_service(array $functionnames): int {
        global $DB;

        $serviceid = (int)$DB->insert_record('external_services', (object)[
            'name' => 'Visibility test ' . random_string(6),
            'shortname' => 'visibility_test_' . random_string(6),
            'enabled' => 1,
            'restrictedusers' => 0,
            'timecreated' => time(),
        ]);
        foreach ($functionnames as $functionname) {
            $DB->insert_record('external_services_functions', (object)[
                'externalserviceid' => $serviceid,
                'functionname' => $functionname,
            ]);
        }

        return $serviceid;
    }

    /**
     * A cached visibility set is reused, also by later requests (new instances).
     */
    public function test_cache_hit_avoids_recompute(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $serviceid = $this->create_service(['core_webservice_get_site_info']);
        $snapshot = (new catalog_builder())->get_snapshot();

        $resolver = new counting_eligibility_resolver();
        $cache = new visibility_cache($resolver);
        $first = $cache->visible_entries($snapshot, [$serviceid], context_system::instance(), get_admin());
        $second = $cache->visible_entries($snapshot, [$serviceid], context_system::instance(), get_admin());
        $this->assertSame(1, $resolver->calls);
        $this->assertSame(array_keys($first), array_keys($second));
        $this->assertArrayHasKey('risk', $second['core_webservice_get_site_info']);
        $this->assertArrayHasKey('inputSchema', $second['core_webservice_get_site_info']);

        $laterresolver = new counting_eligibility_resolver();
        (new visibility_cache($laterresolver))->visible_entries($snapshot, [$serviceid], context_system::instance(), get_admin());
        $this->assertSame(0, $laterresolver->calls);
    }

    /**
     * Role assignments and capability changes invalidate the cached set on the next request.
     */
    public function test_permission_changes_invalidate(): void {
        $this->resetAfterTest(true);
        $serviceid = $this->create_service(['core_user_create_users']);
        $snapshot = (new catalog_builder())->get_snapshot();
        $user = $this->getDataGenerator()->create_user();
        $system = context_system::instance();
        $this->setUser($user);

        $likely = function () use ($snapshot, $serviceid, $system, $user): bool {
            $entries = (new visibility_cache())->visible_entries($snapshot, [$serviceid], $system, $user);
            return $entries['core_user_create_users']['eligibility']['likelyPermitted'];
        };
        $this->assertFalse($likely());

        // Assign a role without the capability: still not likely permitted, but the key changed.
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $user->id, $system);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse($likely());

        // Granting the capability to the role the user already holds takes effect immediately.
        assign_capability('moodle/user:create', CAP_ALLOW, $roleid, $system);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue($likely());
    }

    /**
     * Rebuilding the catalog snapshot purges cached visibility sets.
     */
    public function test_catalog_rebuild_purges(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $serviceid = $this->create_service(['core_webservice_get_site_info']);
        $builder = new catalog_builder();
        $snapshot = $builder->get_snapshot();

        (new visibility_cache())->visible_entries($snapshot, [$serviceid], context_system::instance(), get_admin());
        $builder->invalidate();

        $resolver = new counting_eligibility_resolver();
        (new visibility_cache($resolver))->visible_entries($snapshot, [$serviceid], context_system::instance(), get_admin());
        $this->assertSame(1, $resolver->calls);
    }
}
