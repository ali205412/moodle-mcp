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
use moodle_exception;
use Exception;
use ReflectionClass;
use webservice_mcp\local\request;
use webservice_mcp\local\server;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * Tests for MCP server class.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\server
 */
final class server_test extends advanced_testcase {
    /**
     * Test server instantiation.
     */
    public function test_server_instantiation(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $this->assertInstanceOf(server::class, $server);
    }

    /**
     * Test is_tool_call method with tools/call request.
     */
    public function test_is_tool_call_true(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to access private method.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        // Create a mock request.
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        $method = $reflection->getMethod('is_tool_call');
        $method->setAccessible(true);

        $result = $method->invoke($server);

        $this->assertTrue($result);
    }

    /**
     * Test is_tool_call method with non-tools/call request.
     */
    public function test_is_tool_call_false(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to access private method.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        // Create a mock request.
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        $method = $reflection->getMethod('is_tool_call');
        $method->setAccessible(true);

        $result = $method->invoke($server);

        $this->assertFalse($result);
    }

    /**
     * Test extract_tool_call with valid request.
     */
    public function test_extract_tool_call_valid(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to set up the request.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
            'params' => [
                'name' => 'test_function',
                'arguments' => ['param1' => 'value1'],
            ],
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        // Call extract_tool_call.
        $server->extract_tool_call();

        // Verify functionname was set.
        $functionnameprop = $reflection->getProperty('functionname');
        $functionnameprop->setAccessible(true);
        $functionname = $functionnameprop->getValue($server);

        $this->assertEquals('test_function', $functionname);

        // Verify parameters were set.
        $parametersprop = $reflection->getProperty('parameters');
        $parametersprop->setAccessible(true);
        $parameters = $parametersprop->getValue($server);

        $this->assertEquals(['param1' => 'value1'], $parameters);
    }

    /**
     * Test extract_tool_call with missing tool name.
     */
    public function test_extract_tool_call_missing_name(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to set up the request.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
            'params' => [],
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        // Expect exception.
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('err_missing_tool_name', 'webservice_mcp'));

        $server->extract_tool_call();
    }

    /**
     * Test the token is read from the Authorization header only.
     */
    public function test_extract_token_header_only(): void {
        $this->resetAfterTest(true);
        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $method = new \ReflectionMethod($server, 'extract_token');
        $method->setAccessible(true);

        $_GET['wstoken'] = 'query_token';
        $_POST['wstoken'] = 'post_token';
        $this->assertNull($method->invoke($server));

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer header_token';
        $this->assertSame('header_token', $method->invoke($server));
        unset($_GET['wstoken'], $_POST['wstoken'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test safe_json_encode with valid data.
     */
    public function test_safe_json_encode_valid(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('safe_json_encode');
        $method->setAccessible(true);

        $data = ['key' => 'value', 'number' => 123];
        $json = $method->invoke($server, $data);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertEquals($data, $decoded);
    }

    /**
     * Test safe_json_encode with unicode characters.
     */
    public function test_safe_json_encode_unicode(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('safe_json_encode');
        $method->setAccessible(true);

        $data = ['text' => 'Hello 世界 🌍'];
        $json = $method->invoke($server, $data);

        $this->assertIsString($json);
        $this->assertStringContainsString('Hello', $json);
        $decoded = json_decode($json, true);
        $this->assertEquals($data, $decoded);
    }

    /**
     * Test server constants are defined.
     */
    public function test_server_constants(): void {
        $this->resetAfterTest(true);

        $reflection = new ReflectionClass(server::class);

        $this->assertTrue($reflection->hasConstant('PROTOCOL_VERSION'));
        $this->assertTrue($reflection->hasConstant('SERVER_NAME'));
        $this->assertTrue($reflection->hasConstant('SERVER_VERSION'));

        $protocolversion = $reflection->getConstant('PROTOCOL_VERSION');
        $servername = $reflection->getConstant('SERVER_NAME');
        $serverversion = $reflection->getConstant('SERVER_VERSION');

        $this->assertIsString($protocolversion);
        $this->assertIsString($servername);
        $this->assertIsString($serverversion);
        $this->assertEquals('2025-03-26', $protocolversion);
        $this->assertEquals('Moodle MCP Server', $servername);
        $this->assertEquals('1.0.0', $serverversion);
    }
}
