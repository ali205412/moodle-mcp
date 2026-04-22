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

/**
 * PHPUnit tests for harvested catalog building and coverage reporting.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use advanced_testcase;
use webservice_mcp\local\catalog\catalog_builder;
use webservice_mcp\local\catalog\wrapper_registry;

require_once(__DIR__ . '/fixtures/testable_catalog_builder.php');

/**
 * Tests for harvested catalog building and coverage reporting.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\catalog\catalog_builder
 * @covers      \webservice_mcp\local\catalog\wrapper_registry
 */
final class catalog_builder_test extends advanced_testcase {
    /**
     * Test harvested entries include normalized provenance and transport hints.
     */
    public function test_catalog_builder_harvests_catalog_entry_metadata(): void {
        global $DB;

        $this->resetAfterTest(true);

        $function = $DB->get_record('external_functions', ['name' => 'core_webservice_get_site_info'], '*', MUST_EXIST);

        $service = (object)[
            'name' => 'Phase 3 Catalog Service',
            'enabled' => 1,
            'requiredcapability' => null,
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => 'phase3_catalog_service',
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ];
        $serviceid = $DB->insert_record('external_services', $service);
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $serviceid,
            'functionname' => $function->name,
        ]);

        $builder = new catalog_builder();
        $snapshot = $builder->get_snapshot(true);
        $entry = $snapshot['entries']['core_webservice_get_site_info'];

        $this->assertSame('core_webservice_get_site_info', $entry['name']);
        $this->assertSame('moodle', $entry['component']);
        $this->assertSame('core', $entry['domain']);
        $this->assertSame('read', $entry['mutability']);
        $this->assertArrayHasKey('provenance', $entry);
        $this->assertArrayHasKey('annotations', $entry);
        $this->assertContains($serviceid, $entry['enabledserviceids']);
        $this->assertArrayHasKey('inputSchema', $entry);
        $this->assertArrayHasKey('outputSchema', $entry);
    }

    /**
     * Test coverage distinguishes disabled functions and unsupported components.
     */
    public function test_catalog_builder_coverage_distinguishes_disabled_and_unsupported_domains(): void {
        global $DB;

        $this->resetAfterTest(true);

        $base = $DB->get_record('external_functions', ['name' => 'core_webservice_get_site_info'], '*', MUST_EXIST);
        $disabled = clone $base;
        unset($disabled->id);
        $disabled->name = 'phase3_disabled_catalog_stub';
        $DB->insert_record('external_functions', $disabled);

        $builder = new testable_catalog_builder(
            new wrapper_registry(),
            ['local' => ['local_gap' => '/tmp/local/gap']]
        );
        $snapshot = $builder->get_snapshot(true);
        $coverage = $snapshot['coverage'];

        $this->assertGreaterThanOrEqual(1, $coverage['core']['disabled']);
        $this->assertSame(1, $coverage['local']['unsupported']);
    }

    /**
     * Test wrapped coverage is counted separately from unsupported.
     */
    public function test_catalog_builder_coverage_counts_wrapped_domains(): void {
        $this->resetAfterTest(true);

        $builder = new testable_catalog_builder(
            new wrapper_registry([
                [
                    'name' => 'local_gap_wrapper',
                    'domain' => 'local',
                    'component' => 'local_gap',
                ],
            ]),
            ['local' => ['local_gap' => '/tmp/local/gap']]
        );

        $snapshot = $builder->get_snapshot(true);
        $coverage = $snapshot['coverage'];

        $this->assertSame(1, $coverage['local']['wrapped']);
        $this->assertSame(0, $coverage['local']['unsupported']);
    }
}
