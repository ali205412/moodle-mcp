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

namespace webservice_mcp\local\transport;

use context_system;
use core_external\restricted_context_exception;
use core\session\manager as session_manager;
use core_external\external_api;
use moodle_exception;
use stdClass;
use webservice_mcp\local\audit\logger as audit_logger;
use webservice_mcp\local\auth\transport_identity;
use webservice_mcp\local\oauth\service as oauth_service;
use webservice_mcp\local\request;
use webservice_mcp\local\server as legacy_server;
use webservice_mcp\local\stream\replay_store;
use webservice_mcp\local\stream\session_store;
use webservice_mcp\local\tool_provider;
use webservice_mcp\local\wrapper\manager as wrapper_manager;

/**
 * Primary Streamable HTTP transport implementation.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server extends legacy_server {
    /** Primary endpoint allowed methods. */
    protected const ALLOW = 'POST, OPTIONS, DELETE, HEAD';

    /** HTTP methods used during CORS negotiation. */
    protected const ALLOW_HEADERS = 'Accept, Content-Type, Authorization, X-Requested-With, '
        . 'MCP-Protocol-Version, MCP-Session-Id, Mcp-Method, Mcp-Name, Last-Event-ID';

    /** @var origin_validator */
    protected origin_validator $originvalidator;

    /** @var protocol_headers */
    protected protocol_headers $protocolheaders;

    /** @var session_store */
    protected session_store $sessionstore;

    /** @var replay_store */
    protected replay_store $replaystore;

    /** @var transport_identity */
    protected transport_identity $identityresolver;

    /** @var audit_logger */
    protected audit_logger $auditlogger;

    /** @var array */
    protected array $rawheaders = [];

    /** @var array|null */
    protected ?array $transportrequest = null;

    /** @var array|null */
    protected ?array $transportsession = null;

    /** @var string|null */
    protected ?string $responseorigin = null;

    /** @var string|null */
    protected ?string $publictoken = null;

    /** @var stdClass|null */
    protected ?stdClass $transportidentity = null;

    /**
     * Constructor.
     *
     * @param int $authmethod Authentication mode.
     * @param origin_validator|null $originvalidator Optional origin validator override.
     * @param protocol_headers|null $protocolheaders Optional protocol header helper.
     * @param session_store|null $sessionstore Optional session store override.
     * @param replay_store|null $replaystore Optional replay store override.
     * @param transport_identity|null $identityresolver Optional identity resolver override.
     * @param audit_logger|null $auditlogger Optional audit logger override.
     */
    public function __construct(
        int $authmethod,
        ?origin_validator $originvalidator = null,
        ?protocol_headers $protocolheaders = null,
        ?session_store $sessionstore = null,
        ?replay_store $replaystore = null,
        ?transport_identity $identityresolver = null,
        ?audit_logger $auditlogger = null
    ) {
        parent::__construct($authmethod);
        $this->originvalidator = $originvalidator ?? new origin_validator();
        $this->protocolheaders = $protocolheaders ?? new protocol_headers();
        $this->sessionstore = $sessionstore ?? new session_store();
        $this->replaystore = $replaystore ?? new replay_store();
        $this->identityresolver = $identityresolver ?? new transport_identity();
        $this->auditlogger = $auditlogger ?? new audit_logger();
    }

    /**
     * Run the Streamable HTTP transport flow.
     *
     * @return void
     */
    public function run(): void {
        raise_memory_limit(MEMORY_EXTRA);
        external_api::set_timeout();

        $this->httpmethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->rawheaders = $this->protocolheaders->read_headers();
        $origin = $this->rawheaders['origin'] ?? null;

        if (!$this->originvalidator->is_origin_allowed($origin)) {
            $this->responseorigin = null;
            $this->send_transport_error(403, -32000, 'Origin not allowed.');
            return;
        }

        $this->responseorigin = $this->originvalidator->get_response_origin($origin);

        try {
            if ($this->httpmethod === 'OPTIONS') {
                $this->send_preflight_response();
                return;
            }

            if ($this->httpmethod === 'DELETE') {
                if (!$this->prepare_stateful_request()) {
                    return;
                }

                $this->authenticate_user();
                $this->release_transport_session();
                $this->handle_delete_request();
                return;
            }

            if ($this->httpmethod === 'GET') {
                $this->send_method_not_allowed();
                return;
            }

            if ($this->httpmethod === 'HEAD') {
                $this->handle_head_request();
                return;
            }

            if ($this->httpmethod !== 'POST') {
                $this->send_method_not_allowed();
                return;
            }

            $this->emit_json_headers();

            try {
                $this->parse_request();
            } catch (\Throwable $exception) {
                $this->send_transport_error(400, -32600, $exception->getMessage());
                return;
            }

            if (!$this->transportrequest['ok']) {
                $this->send_transport_error(
                    $this->transportrequest['status'],
                    $this->transportrequest['errorcode'],
                    $this->transportrequest['message'],
                    $this->mcprequest?->id ?? null
                );
                return;
            }

            $this->authenticate_user();
            $this->release_transport_session();

            if (!empty($this->transportrequest['initialization'])) {
                if (!$this->ensure_oauth_scope(false)) {
                    $this->session_cleanup();
                    return;
                }
                $this->send_initialize_response();
                $this->session_cleanup();
                return;
            }

            if (!$this->load_transport_session_or_respond((string)$this->transportrequest['sessionid'])) {
                $this->session_cleanup();
                return;
            }

            if (empty($this->functionname)) {
                $this->handle_transport_method();
                $this->session_cleanup();
                return;
            }

            if ($this->should_execute_wrapper()) {
                $this->execute_wrapper_tool();
                $this->session_cleanup();
                return;
            }

            $this->load_function_info();
            if (!$this->ensure_external_function_scope()) {
                $this->session_cleanup();
                return;
            }
            $this->execute();
            $this->send_response();
            $this->session_cleanup();
        } catch (\Throwable $exception) {
            abort_all_db_transactions();
            $this->session_cleanup($exception);
            $this->send_error($exception);
        }
    }

    /**
     * Parse the incoming POST request.
     *
     * @return void
     */
    protected function parse_request(): void {
        parent::set_web_service_call_settings();

        $this->token = $this->extract_token();
        $this->publictoken = $this->token;

        $this->mcprequest = request::from_raw_input();
        $this->transportrequest = $this->protocolheaders->validate(
            $this->httpmethod,
            $this->rawheaders,
            $this->mcprequest
        );

        if ($this->transportrequest['ok'] && $this->is_tool_call()) {
            $this->extract_tool_call();
        }
    }

    /**
     * Authenticate either a plugin credential or a raw external token.
     *
     * @return void
     */
    protected function authenticate_user(): void {
        if (!empty($this->publictoken)) {
            $identity = $this->identityresolver->resolve($this->publictoken);
            if ($identity !== null) {
                $this->authenticate_transport_identity($identity);
                return;
            }
        }

        parent::authenticate_user();
    }

    /**
     * Apply plugin-managed credential identity to the core WS runtime.
     *
     * @param stdClass $identity Resolved transport identity.
     * @return void
     */
    protected function authenticate_transport_identity(stdClass $identity): void {
        global $DB, $CFG;

        $user = $identity->user;
        $service = $DB->get_record('external_services', [
            'shortname' => $identity->restrictedservice,
            'enabled' => 1,
        ]);

        if (!$service) {
            throw new moodle_exception('servicenotavailable', 'webservice');
        }

        if ($service->requiredcapability &&
                !has_capability($service->requiredcapability, context_system::instance(), $user)) {
            throw new \webservice_access_exception('The capability ' . $service->requiredcapability . ' is required.');
        }

        if (!empty($service->restrictedusers)) {
            $authoriseduser = $DB->get_record('external_services_users', [
                'externalserviceid' => $service->id,
                'userid' => $user->id,
            ]);

            if (empty($authoriseduser)) {
                throw new \webservice_access_exception(
                    'The user is not allowed for the configured connector service.'
                );
            }

            if (!empty($authoriseduser->validuntil) && (int)$authoriseduser->validuntil < time()) {
                throw new \webservice_access_exception('Invalid service - service expired for this user.');
            }

            if (!empty($authoriseduser->iprestriction) &&
                    !address_in_subnet(getremoteaddr(), $authoriseduser->iprestriction)) {
                throw new \webservice_access_exception('Invalid service - IP is not supported for this user.');
            }
        }

        // Mirror the essential user-state checks from Moodle's WS authentication flow.
        $hasmaintenanceaccess = has_capability('moodle/site:maintenanceaccess', context_system::instance(), $user);
        if (!empty($CFG->maintenance_enabled) && !$hasmaintenanceaccess) {
            throw new moodle_exception('sitemaintenance', 'admin');
        }

        if (!empty($user->deleted)) {
            throw new moodle_exception('wsaccessuserdeleted', 'webservice', '', $user->username);
        }

        if (empty($user->confirmed)) {
            throw new moodle_exception('wsaccessuserunconfirmed', 'webservice', '', $user->username);
        }

        if (!empty($user->suspended)) {
            throw new moodle_exception('wsaccessusersuspended', 'webservice', '', $user->username);
        }

        if ($user->auth === 'nologin') {
            throw new moodle_exception('wsaccessusernologin', 'webservice', '', $user->username);
        }

        $auth = get_auth_plugin($user->auth);
        if (!empty($auth->config->expiration) && (int)$auth->config->expiration === 1) {
            $days2expire = $auth->password_expire($user->username);
            if ((int)$days2expire < 0) {
                throw new moodle_exception('wsaccessuserexpired', 'webservice', '', $user->username);
            }
        }

        enrol_check_plugins($user, false);
        session_manager::set_user($user);
        set_login_session_preferences();

        $this->transportidentity = $identity;
        $this->userid = $user->id;
        $this->restricted_context = $identity->restrictedcontext;
        $this->restricted_serviceid = (int)$service->id;

        if (!empty($identity->resourceuri) &&
                !hash_equals((new oauth_service())->canonical_resource_uri(), (string)$identity->resourceuri)) {
            throw new moodle_exception('invalidtoken', 'webservice');
        }

        if ($this->authmethod !== WEBSERVICE_AUTHMETHOD_SESSION_TOKEN &&
                !has_capability("webservice/{$this->wsname}:use", $this->restricted_context, $user)) {
            throw new \webservice_access_exception(
                "You are not allowed to use the {$this->wsname} protocol "
                . "(missing capability: webservice/{$this->wsname}:use)"
            );
        }

        external_api::set_context_restriction($this->restricted_context);
    }

    /**
     * Send the initialize response and issue a transport session id.
     *
     * @return void
     */
    protected function send_initialize_response(): void {
        $sessionid = $this->create_transport_session();
        $protocolversion = $this->negotiate_protocol_version();

        $result = [
            'protocolVersion' => $protocolversion,
            'capabilities' => [
                'tools' => ['listChanged' => true],
            ],
            'serverInfo' => [
                'name' => static::SERVER_NAME,
                'version' => static::SERVER_VERSION,
            ],
            'instructions' => 'Moodle MCP server initialized successfully',
        ];

        $payload = [
            'jsonrpc' => $this->mcprequest->jsonrpc,
            'id' => $this->mcprequest->id,
            'result' => $result,
        ];

        $this->send_header('MCP-Session-Id: ' . $sessionid);
        $this->set_status(200);
        $this->record_transport_event($sessionid, $payload);
        $this->emit($this->safe_json_encode($payload));
    }

    /**
     * Send the tools/list response for the resolved connector service.
     *
     * @return void
     */
    protected function send_tools_list_response(): void {
        $result = tool_provider::list_tools_for_service_ids(
            [(int)$this->restricted_serviceid],
            [
                'cursor' => $this->mcprequest->params['cursor'] ?? null,
                'limit' => $this->mcprequest->params['limit'] ?? $this->mcprequest->params['pageSize'] ?? null,
                'group' => $this->mcprequest->params['group'] ?? null,
                'restrictedcontext' => $this->restricted_context,
                'user' => $this->transportidentity->user ?? ($GLOBALS['USER'] ?? null),
                'connector_mode' => $this->connector_mode(),
                'allow_wrappers' => $this->transportidentity !== null,
            ]
        );
        $result['nextCursor'] ??= null;
        $result['groups'] ??= [];
        $result['coverage'] ??= [];
        foreach ($result['tools'] as &$tool) {
            $tool['x-moodle']['surface'] ??= ['surface' => 'general', 'area' => 'general'];
            $tool['x-moodle']['workflow'] ??= [];
            $tool['x-moodle']['execution'] ??= [
                'mode' => 'sync',
                'followupTools' => [],
                'notes' => [],
            ];
        }
        unset($tool);
        if ($auditid = $this->record_audit_event('discover', null, false, 'success')) {
            $result['audit'] = ['id' => $auditid];
        }

        $payload = [
            'jsonrpc' => $this->mcprequest->jsonrpc,
            'id' => $this->mcprequest->id,
            'result' => $result,
        ];

        $this->set_status(200);
        $this->record_transport_event((string)$this->transportrequest['sessionid'], $payload);
        $this->emit($this->safe_json_encode($payload));
    }

    /**
     * Send a successful tools/call response and store it for replay.
     *
     * @return void
     */
    protected function send_response(): void {
        $validatedvalues = null;
        $exception = null;

        try {
            if ($this->function->returns_desc !== null) {
                $validatedvalues = external_api::clean_returnvalue(
                    $this->function->returns_desc,
                    $this->returns
                );
            } else {
                $validatedvalues = $this->returns;
            }
        } catch (\Exception $exception) {
            // Handled below.
        }

        if ($exception !== null) {
            $this->send_error($exception);
            return;
        }

        $validatedvalues = [
            'result' => $validatedvalues,
        ];

        $content = [
            'type' => 'text',
            'text' => json_encode($validatedvalues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $result = [
            'content' => [$content],
            'structuredContent' => $validatedvalues,
        ];
        if ($auditid = $this->record_audit_event(
            'tool_call',
            $this->functionname ?: null,
            $this->current_request_is_mutating(),
            'success'
        )) {
            $result['audit'] = ['id' => $auditid];
        }

        $payload = [
            'jsonrpc' => $this->mcprequest->jsonrpc,
            'id' => $this->mcprequest->id,
            'result' => $result,
        ];

        $this->set_status(200);
        $this->record_transport_event((string)$this->transportrequest['sessionid'], $payload);
        $this->emit($this->safe_json_encode($payload));
    }

    /**
     * Send a protocol-aware error response.
     *
     * @param \Throwable|null $ex Optional exception.
     * @return void
     */
    protected function send_error($ex = null): void {
        $this->emit_json_headers();
        $status = $this->exception_status($ex);
        if ($status === 401 && $this->oauth_service()->is_enabled()) {
            $this->send_header('WWW-Authenticate: ' . $this->oauth_service()->build_bearer_challenge(
                'invalid_token',
                'Authorization is required to access this MCP server.',
                $this->oauth_default_scope()
            ));
        }
        $this->set_status($status);

        if ($ex !== null && debugging('', DEBUG_MINIMAL)) {
            $this->log_exception_for_debug($ex);
        }

        $this->emit($this->safe_json_encode($this->generate_error($ex)));
    }

    /**
     * Add structured restriction metadata to generated transport errors.
     *
     * @param mixed $ex Exception-like payload.
     * @return array
     */
    protected function generate_error($ex): array {
        $error = parent::generate_error($ex);
        $restriction = $this->restriction_details_for_exception($ex);
        if ($restriction !== null) {
            $error['error']['data']['restriction'] = $restriction;
        }
        if ($auditid = $this->record_audit_event(
            $this->audit_action_name(),
            $this->functionname ?: null,
            $this->current_request_is_mutating(),
            'error',
            $this->audit_detail_code($ex)
        )) {
            $error['error']['data']['auditId'] = $auditid;
        }

        return $error;
    }

    /**
     * Release the Moodle session lock after auth.
     *
     * @return void
     */
    protected function release_transport_session(): void {
        $this->close_session_for_transport();
    }

    /**
     * Wrapper around Moodle's session unlock for testability.
     *
     * @return void
     */
    protected function close_session_for_transport(): void {
        session_manager::write_close();
    }

    /**
     * Create a new MCP transport session.
     *
     * @return string
     */
    protected function create_transport_session(): string {
        $protocolversion = $this->negotiate_protocol_version();
        $metadata = [
            'userid' => (int)$this->userid,
            'contextid' => (int)$this->restricted_context->id,
            'serviceid' => (int)$this->restricted_serviceid,
            'serviceidentifier' => $this->transportidentity->restrictedservice ?? '',
            'tokenhash' => $this->token_hash(),
            'protocolversion' => $protocolversion,
        ];

        $sessionid = $this->sessionstore->create_session($metadata);
        $this->transportsession = $this->sessionstore->get_session($sessionid);

        return $sessionid;
    }

    /**
     * Load and validate an existing transport session.
     *
     * @param string $sessionid Session id.
     * @return bool
     */
    protected function load_transport_session_or_respond(string $sessionid): bool {
        $session = $this->sessionstore->get_session($sessionid);
        if ($session === null) {
            $this->send_transport_error(404, -32001, 'Unknown MCP session.', $this->mcprequest?->id ?? null);
            return false;
        }

        if (($session['tokenhash'] ?? '') !== $this->token_hash()) {
            $this->send_transport_error(404, -32001, 'Unknown MCP session.', $this->mcprequest?->id ?? null);
            return false;
        }

        if ((int)($session['userid'] ?? 0) !== (int)$this->userid) {
            $this->send_transport_error(404, -32001, 'Unknown MCP session.', $this->mcprequest?->id ?? null);
            return false;
        }

        if ((int)($session['contextid'] ?? 0) !== (int)$this->restricted_context->id) {
            $this->send_transport_error(404, -32001, 'Unknown MCP session.', $this->mcprequest?->id ?? null);
            return false;
        }

        if ((int)($session['serviceid'] ?? 0) !== (int)$this->restricted_serviceid) {
            $this->send_transport_error(404, -32001, 'Unknown MCP session.', $this->mcprequest?->id ?? null);
            return false;
        }

        if (($session['protocolversion'] ?? '') !== ($this->transportrequest['protocolversion'] ?? '')) {
            $this->send_transport_error(
                400,
                -32001,
                'MCP-Protocol-Version does not match the initialized session.',
                $this->mcprequest?->id ?? null
            );
            return false;
        }

        $this->sessionstore->touch_session($sessionid, [
            'lastmethod' => $this->transportrequest['mcpmethod'] ?? '',
        ]);
        $this->transportsession = $this->sessionstore->get_session($sessionid);

        return true;
    }

    /**
     * Handle non-tools control methods after session validation.
     *
     * @return void
     */
    protected function handle_transport_method(): void {
        if ($this->mcprequest === null) {
            $this->send_transport_error(400, -32600, 'Invalid Request');
            return;
        }

        if ($this->mcprequest->id === null && strpos($this->mcprequest->method, 'notifications/') === 0) {
            $this->sessionstore->touch_session((string)$this->transportrequest['sessionid'], [
                'lastnotification' => $this->mcprequest->method,
            ]);
            $this->set_status(202);
            return;
        }

        switch ($this->mcprequest->method) {
            case 'tools/list':
                if (!$this->ensure_oauth_scope(false)) {
                    return;
                }
                $this->send_tools_list_response();
                return;

            case 'initialize':
                $this->send_transport_error(400, -32600, 'Initialize must start a new transport session.', $this->mcprequest->id);
                return;

            default:
                $this->send_transport_error(400, -32601, 'Method not found', $this->mcprequest->id);
        }
    }

    /**
     * Terminate a transport session and any stored replay state.
     *
     * @return void
     */
    protected function handle_delete_request(): void {
        if (!$this->load_transport_session_or_respond((string)$this->transportrequest['sessionid'])) {
            return;
        }

        $sessionid = (string)$this->transportrequest['sessionid'];
        $this->sessionstore->delete_session($sessionid);
        $this->replaystore->clear($sessionid);
        $this->set_status(204);
    }

    /**
     * Prepare DELETE/GET style stateful requests.
     *
     * @return bool
     */
    protected function prepare_stateful_request(): bool {
        $this->emit_json_headers(false);
        $this->token = $this->extract_token();
        $this->publictoken = $this->token;
        $this->transportrequest = $this->protocolheaders->validate($this->httpmethod, $this->rawheaders, null);

        if ($this->transportrequest['ok']) {
            return true;
        }

        $this->send_transport_error(
            $this->transportrequest['status'],
            $this->transportrequest['errorcode'],
            $this->transportrequest['message']
        );
        return false;
    }

    /**
     * Send an explicit preflight response before any auth.
     *
     * @return void
     */
    protected function send_preflight_response(): void {
        $this->emit_json_headers(false);
        $this->send_header('Access-Control-Max-Age: 600');
        $this->set_status(204);
    }

    /**
     * Send a method-not-allowed response.
     *
     * @return void
     */
    protected function send_method_not_allowed(): void {
        $this->emit_json_headers();
        $this->send_header('Allow: ' . static::ALLOW);
        $this->send_transport_error(
            405,
            -32601,
            'HTTP method ' . $this->httpmethod . ' is not supported on this endpoint.'
        );
    }

    /**
     * Emit transport headers common to real and preflight requests.
     *
     * @param bool $withcontenttype Whether to send JSON content type.
     * @return void
     */
    protected function emit_json_headers(bool $withcontenttype = true): void {
        if ($withcontenttype) {
            $this->send_header('Content-Type: application/json; charset=utf-8');
        }

        $this->send_header('Cache-Control: private, must-revalidate, max-age=0');
        $this->send_header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
        $this->send_header('Pragma: no-cache');
        $this->send_header('Access-Control-Allow-Methods: ' . static::ALLOW);
        $this->send_header('Access-Control-Allow-Headers: ' . static::ALLOW_HEADERS);
        $this->send_header('Access-Control-Expose-Headers: MCP-Session-Id');
        $this->send_header('Vary: Origin');

        if ($this->responseorigin !== null) {
            $this->send_header('Access-Control-Allow-Origin: ' . $this->responseorigin);
        }
    }

    /**
     * Wrapper around header() for testability.
     *
     * @param string $header Header line.
     * @return void
     */
    protected function send_header(string $header): void {
        header($header);
    }

    /**
     * Wrapper around http_response_code() for testability.
     *
     * @param int $status HTTP status.
     * @return void
     */
    protected function set_status(int $status): void {
        http_response_code($status);
    }

    /**
     * Wrapper around echo for testability.
     *
     * @param string $body Response body.
     * @return void
     */
    protected function emit(string $body): void {
        echo $body;
    }

    /**
     * Send a JSON-RPC error response using an explicit HTTP status.
     *
     * @param int $status HTTP status.
     * @param int $code JSON-RPC error code.
     * @param string $message Error message.
     * @param mixed $id Optional request id.
     * @return void
     */
    protected function send_transport_error(int $status, int $code, string $message, mixed $id = null): void {
        $this->emit_json_headers();
        $this->set_status($status);

        $payload = [
            'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => $id,
        ];

        $this->emit($this->safe_json_encode($payload));
    }

    /**
     * Map an exception to an HTTP status.
     *
     * @param \Throwable|null $exception Optional exception.
     * @return int
     */
    protected function exception_status(?\Throwable $exception): int {
        if ($exception instanceof \invalid_parameter_exception) {
            return 400;
        }

        if ($exception instanceof restricted_context_exception) {
            return 403;
        }

        if ($exception instanceof \required_capability_exception || $exception instanceof \webservice_access_exception) {
            return 403;
        }

        if ($exception instanceof moodle_exception && ($exception->errorcode ?? '') === 'invalidtoken') {
            return 401;
        }

        return 500;
    }

    /**
     * Return connector mode metadata for the current request.
     *
     * @return string
     */
    protected function connector_mode(): string {
        if ($this->transportidentity === null) {
            return 'external_token';
        }

        return match ((int)($this->transportidentity->tokentype ?? -1)) {
            0 => 'bootstrap',
            1 => 'durable',
            default => 'connector',
        };
    }

    /**
     * Build structured restriction metadata from a Moodle exception.
     *
     * @param mixed $exception Exception-like payload.
     * @return array|null
     */
    protected function restriction_details_for_exception(mixed $exception): ?array {
        if (!$exception instanceof \Throwable) {
            return null;
        }

        if ($exception instanceof \required_capability_exception) {
            return [
                'category' => 'capability',
                'code' => 'missing_capability',
                'retryable' => false,
            ];
        }

        if ($exception instanceof restricted_context_exception) {
            return [
                'category' => 'context',
                'code' => 'restricted_context',
                'retryable' => true,
            ];
        }

        if ($exception instanceof \webservice_access_exception) {
            return [
                'category' => 'service',
                'code' => 'webservice_access_denied',
                'retryable' => false,
            ];
        }

        if ($exception instanceof moodle_exception) {
            return match ($exception->errorcode ?? '') {
                'servicerequireslogin', 'requireloginerror', 'requirelogin' => [
                    'category' => 'authentication',
                    'code' => 'login_required',
                    'retryable' => true,
                ],
                'notingroup' => [
                    'category' => 'group',
                    'code' => 'group_membership_required',
                    'retryable' => false,
                ],
                'nopermissions' => [
                    'category' => 'capability',
                    'code' => 'permission_denied',
                    'retryable' => false,
                ],
                'invalidtoken' => [
                    'category' => 'authentication',
                    'code' => 'invalid_token',
                    'retryable' => true,
                ],
                default => null,
            };
        }

        return null;
    }

    /**
     * Determine whether the current tool call targets a wrapper tool.
     *
     * @return bool
     */
    protected function should_execute_wrapper(): bool {
        if ($this->transportidentity === null || empty($this->functionname)) {
            return false;
        }

        return (new wrapper_manager())->find($this->functionname) !== null;
    }

    /**
     * Execute a wrapper tool and emit a tools/call compatible response.
     *
     * @return void
     */
    protected function execute_wrapper_tool(): void {
        $wrappers = new wrapper_manager();
        if (!$this->ensure_oauth_scope($wrappers->is_mutating($this->functionname))) {
            return;
        }

        $result = $wrappers->execute(
            $this->functionname,
            is_array($this->parameters) ? $this->parameters : [],
            $this->restricted_context,
            $this->transportidentity->user ?? ($GLOBALS['USER'] ?? null)
        );

        $validatedvalues = ['result' => $result];
        $content = [
            'type' => 'text',
            'text' => json_encode($validatedvalues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $payload = [
            'jsonrpc' => $this->mcprequest->jsonrpc,
            'id' => $this->mcprequest->id,
            'result' => [
                'content' => [$content],
                'structuredContent' => $validatedvalues,
            ],
        ];
        if ($auditid = $this->record_audit_event('tool_call', $this->functionname, true, 'success')) {
            $payload['result']['audit'] = ['id' => $auditid];
        }

        $this->set_status(200);
        $this->record_transport_event((string)$this->transportrequest['sessionid'], $payload);
        $this->emit($this->safe_json_encode($payload));
    }

    /**
     * Persist one audit event without breaking the transport on logging failures.
     *
     * @param string $action Audit action type.
     * @param string|null $toolname Tool name when applicable.
     * @param bool $mutating Whether the request mutates state.
     * @param string $outcome Event outcome.
     * @param string|null $detailcode Optional restriction or error code.
     * @return string|null
     */
    protected function record_audit_event(
        string $action,
        ?string $toolname,
        bool $mutating,
        string $outcome,
        ?string $detailcode = null
    ): ?string {
        try {
            $userid = isset($this->transportidentity->user->id) ? (int)$this->transportidentity->user->id : ($this->userid ?? null);
            $credentialid = isset($this->transportidentity->credential->id)
                ? (int)$this->transportidentity->credential->id
                : null;
            $contextid = isset($this->restricted_context->id)
                ? (int)$this->restricted_context->id
                : (isset($this->transportidentity->restrictedcontext->id)
                    ? (int)$this->transportidentity->restrictedcontext->id
                    : null);

            return $this->auditlogger->record([
                'userid' => $userid,
                'credentialid' => $credentialid,
                'contextid' => $contextid,
                'serviceid' => $this->restricted_serviceid ?? null,
                'sessionid' => $this->transportrequest['sessionid'] ?? null,
                'requestid' => $this->request_id_string(),
                'action' => $action,
                'toolname' => $toolname,
                'mutating' => $mutating,
                'outcome' => $outcome,
                'detailcode' => $detailcode,
            ]);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Determine the audit action name for the current request.
     *
     * @return string
     */
    protected function audit_action_name(): string {
        if (($this->mcprequest instanceof request) && $this->mcprequest->method === 'tools/list') {
            return 'discover';
        }

        if (!empty($this->functionname)) {
            return 'tool_call';
        }

        return 'request';
    }

    /**
     * Determine whether the current request mutates Moodle state.
     *
     * @return bool
     */
    protected function current_request_is_mutating(): bool {
        if (!empty($this->functionname) && $this->transportidentity !== null &&
                (new wrapper_manager())->find($this->functionname) !== null) {
            return (new wrapper_manager())->is_mutating($this->functionname);
        }

        if (isset($this->function->type)) {
            return (string)$this->function->type !== 'read';
        }

        return false;
    }

    /**
     * Handle HEAD probes without emitting a response body.
     *
     * @return void
     */
    protected function handle_head_request(): void {
        $this->emit_json_headers(false);
        $this->token = $this->extract_token();
        $this->publictoken = $this->token;

        if (empty($this->publictoken)) {
            if ($this->oauth_service()->is_enabled()) {
                $this->send_header('WWW-Authenticate: ' . $this->oauth_service()->build_bearer_challenge(
                    'invalid_token',
                    'Authorization is required to access this MCP server.',
                    $this->oauth_default_scope()
                ));
            }
            $this->set_status(401);
            return;
        }

        try {
            $this->authenticate_user();
            $this->release_transport_session();
            $this->set_status(204);
        } catch (\Throwable $exception) {
            $status = $this->exception_status($exception);
            if ($status === 401 && $this->oauth_service()->is_enabled()) {
                $this->send_header('WWW-Authenticate: ' . $this->oauth_service()->build_bearer_challenge(
                    'invalid_token',
                    'Authorization is required to access this MCP server.',
                    $this->oauth_default_scope()
                ));
            }
            $this->set_status($status);
        }
    }

    /**
     * Convert the current JSON-RPC id into a stable string form for audit storage.
     *
     * @return string|null
     */
    protected function request_id_string(): ?string {
        if (!($this->mcprequest instanceof request) || !isset($this->mcprequest->id) || $this->mcprequest->id === null) {
            return null;
        }

        if (is_scalar($this->mcprequest->id)) {
            return (string)$this->mcprequest->id;
        }

        return json_encode($this->mcprequest->id);
    }

    /**
     * Extract a stable detail code for audit storage.
     *
     * @param mixed $exception Exception-like payload.
     * @return string|null
     */
    protected function audit_detail_code(mixed $exception): ?string {
        $restriction = $this->restriction_details_for_exception($exception);
        if ($restriction !== null) {
            return (string)$restriction['code'];
        }

        if ($exception instanceof moodle_exception && !empty($exception->errorcode)) {
            return (string)$exception->errorcode;
        }

        if ($exception instanceof \Throwable) {
            return strtolower((new \ReflectionClass($exception))->getShortName());
        }

        return null;
    }

    /**
     * Persist a response for optional SSE replay.
     *
     * @param string $sessionid Session id.
     * @param array $payload Response payload.
     * @return void
     */
    protected function record_transport_event(string $sessionid, array $payload): void {
        if ($sessionid === '') {
            return;
        }

        $this->replaystore->append_event($sessionid, [
            'type' => 'message',
            'payload' => $payload,
        ]);
    }

    /**
     * Negotiate the protocol version for the current initialize request.
     *
     * @return string
     */
    protected function negotiate_protocol_version(): string {
        if (!($this->mcprequest instanceof request)) {
            return protocol_headers::DEFAULT_PROTOCOL_VERSION;
        }

        $requested = $this->mcprequest->params['protocolVersion'] ?? null;
        if (is_string($requested) && $this->protocolheaders->is_supported_protocol_version($requested)) {
            return $requested;
        }

        return protocol_headers::DEFAULT_PROTOCOL_VERSION;
    }

    /**
     * Stable token hash used to bind transport sessions to auth state.
     *
     * @return string
     */
    protected function token_hash(): string {
        return hash('sha256', (string)($this->publictoken ?? $this->token ?? ''));
    }

    /**
     * Return the plugin OAuth helper.
     *
     * @return oauth_service
     */
    protected function oauth_service(): oauth_service {
        return new oauth_service();
    }

    /**
     * Return the default scope hint used in Bearer challenges.
     *
     * @return string
     */
    protected function oauth_default_scope(): string {
        return $this->oauth_service()->default_scope_string();
    }

    /**
     * Determine whether the current connector token is OAuth-scoped.
     *
     * @return bool
     */
    protected function oauth_scope_enforced(): bool {
        if ($this->transportidentity === null) {
            return false;
        }

        return !empty($this->transportidentity->oauthclientid)
            || !empty($this->transportidentity->resourceuri)
            || trim((string)($this->transportidentity->scope ?? '')) !== '';
    }

    /**
     * Ensure the current request has the required OAuth scope.
     *
     * @param bool $write Whether the request needs write scope.
     * @return bool
     */
    protected function ensure_oauth_scope(bool $write): bool {
        if (!$this->oauth_scope_enforced()) {
            return true;
        }

        $requiredscope = $write ? oauth_service::SCOPE_WRITE : oauth_service::SCOPE_READ;
        $grantedscope = (string)($this->transportidentity->scope ?? '');

        if (oauth_service::scope_contains($grantedscope, $requiredscope)) {
            return true;
        }

        $this->send_oauth_scope_error($requiredscope);
        return false;
    }

    /**
     * Ensure the current harvested external function has the correct scope.
     *
     * @return bool
     */
    protected function ensure_external_function_scope(): bool {
        if (!$this->oauth_scope_enforced()) {
            return true;
        }

        $requireswrite = isset($this->function->type) && (string)$this->function->type !== 'read';
        return $this->ensure_oauth_scope($requireswrite);
    }

    /**
     * Send a 403 insufficient-scope response.
     *
     * @param string $requiredscope Required OAuth scope.
     * @return void
     */
    protected function send_oauth_scope_error(string $requiredscope): void {
        $this->emit_json_headers();
        $this->send_header('WWW-Authenticate: ' . $this->oauth_service()->build_bearer_challenge(
            'insufficient_scope',
            'The presented access token does not grant the required scope.',
            $requiredscope
        ));
        $this->set_status(403);

        $payload = [
            'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
            'error' => [
                'code' => -32003,
                'message' => 'Insufficient OAuth scope.',
                'data' => [
                    'requiredScope' => $requiredscope,
                ],
            ],
            'id' => $this->mcprequest->id ?? null,
        ];

        if ($auditid = $this->record_audit_event(
            $this->audit_action_name(),
            $this->functionname ?: null,
            $requiredscope === oauth_service::SCOPE_WRITE,
            'error',
            'insufficient_scope'
        )) {
            $payload['error']['data']['auditId'] = $auditid;
        }

        $this->emit($this->safe_json_encode($payload));
    }
}
