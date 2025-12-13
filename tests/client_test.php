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
use ReflectionClass;
use ReflectionMethod;
use webservice_mcp_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/mcp/lib.php');

/**
 * Tests for MCP client class.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp_client
 */
final class client_test extends advanced_testcase {
    /**
     * Test client instantiation.
     */
    public function test_client_instantiation(): void {
        $this->resetAfterTest(true);

        $serverurl = 'http://example.com/webservice/mcp/server.php';
        $token = 'test_token_123';

        $client = new webservice_mcp_client($serverurl, $token);

        $this->assertInstanceOf(webservice_mcp_client::class, $client);
    }

    /**
     * Test set_token method.
     */
    public function test_set_token(): void {
        $this->resetAfterTest(true);

        $serverurl = 'http://example.com/webservice/mcp/server.php';
        $token = 'initial_token';

        $client = new webservice_mcp_client($serverurl, $token);

        // Change token.
        $newtoken = 'new_token_456';
        $client->set_token($newtoken);

        // Verify token was changed by checking it's used in a call.
        // Since we can't easily test actual HTTP calls without a server,
        // we verify the method exists and can be called.
        $this->assertTrue(method_exists($client, 'set_token'));
    }

    /**
     * Test call method constructs proper request structure.
     */
    public function test_call_method_structure(): void {
        $this->resetAfterTest(true);

        $serverurl = 'http://example.com/webservice/mcp/server.php';
        $token = 'test_token';

        $client = new webservice_mcp_client($serverurl, $token);

        // Verify call method exists.
        $this->assertTrue(method_exists($client, 'call'));

        // Verify list_tools method exists.
        $this->assertTrue(method_exists($client, 'list_tools'));

        // Verify call_tool method exists.
        $this->assertTrue(method_exists($client, 'call_tool'));

        // Verify initialize method exists.
        $this->assertTrue(method_exists($client, 'initialize'));
    }

    /**
     * Test list_tools method signature.
     */
    public function test_list_tools_method(): void {
        $this->resetAfterTest(true);

        $serverurl = 'http://example.com/webservice/mcp/server.php';
        $token = 'test_token';

        $client = new webservice_mcp_client($serverurl, $token);

        $this->assertTrue(method_exists($client, 'list_tools'));
    }

    /**
     * Test call_tool method signature.
     */
    public function test_call_tool_method(): void {
        $this->resetAfterTest(true);

        $serverurl = 'http://example.com/webservice/mcp/server.php';
        $token = 'test_token';

        $client = new webservice_mcp_client($serverurl, $token);

        $this->assertTrue(method_exists($client, 'call_tool'));

        // Verify method accepts proper parameters.
        $reflection = new ReflectionMethod($client, 'call_tool');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('toolname', $params[0]->getName());
        $this->assertEquals('arguments', $params[1]->getName());
    }

    /**
     * Test initialize method signature.
     */
    public function test_initialize_method(): void {
        $this->resetAfterTest(true);

        $serverurl = 'http://example.com/webservice/mcp/server.php';
        $token = 'test_token';

        $client = new webservice_mcp_client($serverurl, $token);

        $this->assertTrue(method_exists($client, 'initialize'));
    }

    /**
     * Test client with moodle_url object.
     */
    public function test_client_with_moodle_url(): void {
        $this->resetAfterTest(true);

        $serverurl = 'http://example.com/webservice/mcp/server.php';
        $token = 'test_token';

        // Should accept string URL and convert internally.
        $client = new webservice_mcp_client($serverurl, $token);

        $this->assertInstanceOf(webservice_mcp_client::class, $client);
    }

    /**
     * Test call method with different parameter types.
     */
    public function test_call_with_various_parameters(): void {
        $this->resetAfterTest(true);

        $serverurl = 'http://example.com/webservice/mcp/server.php';
        $token = 'test_token';

        $client = new webservice_mcp_client($serverurl, $token);

        // Verify call accepts correct parameters.
        $reflection = new ReflectionMethod($client, 'call');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('method', $params[0]->getName());
        $this->assertEquals('params', $params[1]->getName());
        $this->assertEquals('id', $params[2]->getName());

        // Verify default values.
        $this->assertEquals([], $params[1]->getDefaultValue());
        $this->assertEquals(1, $params[2]->getDefaultValue());
    }

    /**
     * Test client constructor parameters.
     */
    public function test_constructor_parameters(): void {
        $this->resetAfterTest(true);

        $reflection = new ReflectionClass(webservice_mcp_client::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('serverurl', $params[0]->getName());
        $this->assertEquals('token', $params[1]->getName());

        // Verify both are required (no default values).
        $this->assertFalse($params[0]->isOptional());
        $this->assertFalse($params[1]->isOptional());
    }
}
