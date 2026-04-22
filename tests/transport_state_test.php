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
use webservice_mcp\local\stream\replay_store;
use webservice_mcp\local\stream\session_store;
use webservice_mcp\local\transport\origin_validator;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for transport policy and state helpers.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\transport\origin_validator
 * @covers      \webservice_mcp\local\stream\session_store
 * @covers      \webservice_mcp\local\stream\replay_store
 */
final class transport_state_test extends advanced_testcase {
    /**
     * Test no-Origin requests are accepted.
     */
    public function test_origin_validator_allows_no_origin(): void {
        $this->resetAfterTest(true);

        $validator = new origin_validator();
        $this->assertTrue($validator->is_origin_allowed(null));
    }

    /**
     * Test configured origin allowlist accepts exact origin.
     */
    public function test_origin_validator_allows_configured_origin(): void {
        $this->resetAfterTest(true);

        set_config('allowedorigins', "https://client.example.com", 'webservice_mcp');
        $validator = new origin_validator();

        $this->assertTrue($validator->is_origin_allowed('https://client.example.com'));
        $this->assertFalse($validator->is_origin_allowed('https://evil.example.com'));
    }

    /**
     * Test session store lifecycle.
     */
    public function test_session_store_create_touch_delete_cycle(): void {
        $this->resetAfterTest(true);

        $store = new session_store();
        $sessionid = $store->create_session(['user' => 2, 'transport' => 'http']);

        $this->assertNotEmpty($sessionid);
        $session = $store->get_session($sessionid);
        $this->assertSame(2, $session['user']);

        $this->assertTrue($store->touch_session($sessionid, ['transport' => 'sse']));
        $updated = $store->get_session($sessionid);
        $this->assertSame('sse', $updated['transport']);

        $this->assertTrue($store->delete_session($sessionid));
        $this->assertNull($store->get_session($sessionid));
    }

    /**
     * Test replay store append and fetch semantics.
     */
    public function test_replay_store_append_and_fetch(): void {
        $this->resetAfterTest(true);

        $store = new replay_store();
        $sessionid = 'phase2-replay-test';

        $firstid = $store->append_event($sessionid, ['type' => 'heartbeat']);
        $secondid = $store->append_event($sessionid, ['type' => 'result']);

        $this->assertSame(1, $firstid);
        $this->assertSame(2, $secondid);
        $this->assertCount(2, $store->get_events($sessionid));
        $this->assertCount(1, $store->get_events($sessionid, 1));

        $this->assertTrue($store->clear($sessionid));
        $this->assertSame([], $store->get_events($sessionid));
    }
}
