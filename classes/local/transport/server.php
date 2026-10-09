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
use core_external\external_api;
use core_external\restricted_context_exception;
use core\session\manager as session_manager;
use moodle_exception;
use stdClass;
use webservice_mcp\local\audit\logger as audit_logger;
use webservice_mcp\local\auth\transport_identity;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\mcp\dispatcher;
use webservice_mcp\local\mcp\protocol_exception;
use webservice_mcp\local\oauth\service as oauth_service;
use webservice_mcp\local\request;
use webservice_mcp\local\server as legacy_server;
use webservice_mcp\local\stream\replay_store;
use webservice_mcp\local\stream\session_store;
use webservice_mcp\local\wrapper\manager as wrapper_manager;

/**
 * Streamable HTTP transport serving both MCP eras on one endpoint.
 *
 * Requests carrying io.modelcontextprotocol/protocolVersion in params._meta are served
 * statelessly (2026-07-28). An initialize request selects initialize/session semantics
 * (2024-11-05 .. 2025-11-25). Protocol semantics live in {@see dispatcher}; this class owns
 * HTTP, authentication, sessions, scope enforcement, audit and tool execution.
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

    /** Headers accepted during CORS negotiation. */
    protected const ALLOW_HEADERS = 'Accept, Content-Type, Authorization, X-Requested-With, '
        . 'MCP-Protocol-Version, MCP-Session-Id, Mcp-Method, Mcp-Name, Last-Event-ID';

    /** Insufficient OAuth scope error code (outside the spec-reserved range). */
    protected const INSUFFICIENT_SCOPE = -32003;

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

    /** @var array|null Legacy header validation result. */
    protected ?array $transportrequest = null;

    /** @var array|null Legacy transport session. */
    protected ?array $transportsession = null;

    /** @var string|null */
    protected ?string $responseorigin = null;

    /** @var string|null */
    protected ?string $publictoken = null;

    /** @var stdClass|null Plugin credential identity (connector mode). */
    protected ?stdClass $transportidentity = null;

    /** @var string Era of the current request. */
    protected string $era = call_context::ERA_LEGACY;

    /** @var bool|null Whether the current tools/call target mutates state. */
    protected ?bool $currentmutating = null;

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
     * Run the transport for one HTTP request.
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
            switch ($this->httpmethod) {
                case 'OPTIONS':
                    $this->send_preflight_response();
                    return;
                case 'HEAD':
                    $this->handle_head_request();
                    return;
                case 'DELETE':
                    $this->handle_delete();
                    return;
                case 'POST':
                    $this->handle_post();
                    return;
                default:
                    // No standalone GET stream: 2026-07-28 removed it and legacy servers may answer 405.
                    $this->send_method_not_allowed();
            }
        } catch (protocol_exception $exception) {
            $this->send_protocol_error($exception, $this->mcprequest?->id ?? null);
        } catch (\Throwable $exception) {
            abort_all_db_transactions();
            $this->session_cleanup($exception);
            $this->send_error($exception);
        }
    }

    /**
     * Handle a JSON-RPC POST of either era.
     *
     * @return void
     */
    protected function handle_post(): void {
        $this->emit_json_headers();
        parent::set_web_service_call_settings();

        try {
            $this->mcprequest = $this->read_request();
        } catch (\Throwable $exception) {
            $code = ($exception instanceof moodle_exception && $exception->errorcode === 'err_invalid_json')
                ? protocol_exception::PARSE_ERROR
                : protocol_exception::INVALID_REQUEST;
            $message = $exception instanceof moodle_exception && !empty($exception->debuginfo)
                ? $exception->debuginfo
                : 'Invalid Request';
            $this->send_transport_error(400, $code, $message);
            return;
        }

        $metaversion = $this->mcprequest->params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
        if (is_string($metaversion)) {
            $this->era = call_context::ERA_MODERN;
            $this->handle_modern_request($metaversion);
            return;
        }

        $this->era = call_context::ERA_LEGACY;
        $this->handle_legacy_request();
    }

    /**
     * Read and validate the JSON-RPC body (seam for tests).
     *
     * @return request
     */
    protected function read_request(): request {
        return request::from_raw_input();
    }

    /**
     * Serve a stateless 2026-07-28 request.
     *
     * @param string $version Requested protocol version from _meta.
     * @return void
     */
    protected function handle_modern_request(string $version): void {
        $request = $this->mcprequest;

        if (!in_array($version, dispatcher::MODERN_VERSIONS, true)) {
            throw new protocol_exception(
                protocol_exception::UNSUPPORTED_PROTOCOL_VERSION,
                'Unsupported protocol version: ' . $version,
                400,
                ['supported' => dispatcher::supported_versions(), 'requested' => $version]
            );
        }

        if ($error = $this->protocolheaders->validate_modern($this->rawheaders, $request, $version)) {
            throw new protocol_exception(protocol_exception::HEADER_MISMATCH, $error, 400);
        }

        if ($request->id === null) {
            // The only core client notification (cancelled) is stdio-only; accept and ignore.
            $this->set_status(202);
            return;
        }

        $meta = $request->params['_meta'];
        $capabilities = is_array($meta['io.modelcontextprotocol/clientCapabilities'] ?? null)
            ? $meta['io.modelcontextprotocol/clientCapabilities'] : [];

        if ($request->method === 'server/discover') {
            $dispatcher = new dispatcher(new call_context(call_context::ERA_MODERN, $version));
            $this->send_result($dispatcher->dispatch('server/discover', []));
            return;
        }

        $this->authenticate_request();
        $this->dispatch_and_respond(call_context::ERA_MODERN, $version, $capabilities);
        $this->session_cleanup();
    }

    /**
     * Serve an initialize/session request (2024-11-05 .. 2025-11-25).
     *
     * @return void
     */
    protected function handle_legacy_request(): void {
        $this->transportrequest = $this->protocolheaders->validate($this->httpmethod, $this->rawheaders, $this->mcprequest);
        if (!$this->transportrequest['ok']) {
            $this->send_transport_error(
                $this->transportrequest['status'],
                $this->transportrequest['errorcode'],
                $this->transportrequest['message'],
                $this->mcprequest->id
            );
            return;
        }

        $this->authenticate_request();

        if (!empty($this->transportrequest['initialization'])) {
            $this->scope_check(false);
            $this->send_initialize_response();
            $this->session_cleanup();
            return;
        }

        if (!$this->load_transport_session_or_respond((string)$this->transportrequest['sessionid'])) {
            $this->session_cleanup();
            return;
        }

        $this->handle_transport_method();
        $this->session_cleanup();
    }

    /**
     * Dispatch a legacy request after session validation.
     *
     * @return void
     */
    protected function handle_transport_method(): void {
        if ($this->mcprequest->id === null) {
            $this->sessionstore->touch_session((string)$this->transportrequest['sessionid'], [
                'lastnotification' => $this->mcprequest->method,
            ]);
            $this->set_status(202);
            return;
        }

        if ($this->mcprequest->method === 'initialize') {
            throw new protocol_exception(
                protocol_exception::INVALID_REQUEST,
                'Initialize must start a new transport session.',
                400
            );
        }

        $this->dispatch_and_respond(
            call_context::ERA_LEGACY,
            (string)($this->transportsession['protocolversion'] ?? protocol_headers::DEFAULT_PROTOCOL_VERSION),
            (array)($this->transportsession['clientcapabilities'] ?? [])
        );
    }

    /**
     * Run the dispatcher for the current request and emit the JSON-RPC response.
     *
     * @param string $era Era.
     * @param string $version Protocol version.
     * @param array $capabilities Client capabilities.
     * @return void
     */
    protected function dispatch_and_respond(string $era, string $version, array $capabilities): void {
        $method = $this->mcprequest->method;
        $params = $this->mcprequest->params ?? [];

        if ($method === 'tools/call') {
            $this->functionname = is_string($params['name'] ?? null) ? $params['name'] : '';
        }

        $result = $this->dispatcher($era, $version, $capabilities)->dispatch($method, $params);

        $action = match ($method) {
            'tools/list' => 'discover',
            'tools/call' => 'tool_call',
            default => null,
        };
        if ($action !== null && ($result['resultType'] ?? 'complete') === 'complete') {
            $auditid = $this->record_audit_event(
                $action,
                $action === 'tool_call' ? $this->functionname : null,
                $action === 'tool_call' && $this->current_request_is_mutating(),
                empty($result['isError']) ? 'success' : 'error',
                $result['_meta']['org.moodle/errorcode'] ?? null,
                // The same text the client received for the failed call.
                empty($result['isError']) ? null : ($result['content'][0]['text'] ?? null)
            );
            if ($auditid) {
                $result['_meta']['org.moodle/auditId'] = $auditid;
            }
        }

        $this->send_result($result);
    }

    /**
     * Build the dispatcher for the authenticated request.
     *
     * @param string $era Era.
     * @param string $version Protocol version.
     * @param array $capabilities Client capabilities.
     * @return dispatcher
     */
    protected function dispatcher(string $era, string $version, array $capabilities): dispatcher {
        global $USER;

        $ctx = new call_context(
            $era,
            $version,
            $this->transportidentity->user ?? $USER,
            $this->restricted_context,
            $this->restricted_serviceid ? (int)$this->restricted_serviceid : null,
            $this->transportidentity !== null,
            $this->connector_mode(),
            $capabilities,
            isset($this->transportidentity->familyid) ? (string)$this->transportidentity->familyid : null,
            fn(bool $write) => $this->scope_check($write)
        );

        return new dispatcher($ctx, fn(string $name, array $arguments): array => $this->execute_tool($name, $arguments));
    }

    /**
     * Execute a wrapper or harvested external function as the current user.
     *
     * @param string $name Tool name.
     * @param array $arguments Tool arguments.
     * @return array Structured payload ['result' => mixed].
     */
    protected function execute_tool(string $name, array $arguments): array {
        global $USER;

        $this->functionname = $name;
        $this->parameters = $arguments;

        if ($this->transportidentity !== null) {
            $wrappers = new wrapper_manager();
            if ($wrappers->find($name) !== null) {
                $this->currentmutating = $wrappers->is_mutating($name, $arguments);
                $this->scope_check($this->currentmutating);
                $this->trigger_function_called($name);
                return ['result' => $wrappers->execute(
                    $name,
                    $arguments,
                    $this->restricted_context,
                    $this->transportidentity->user ?? $USER,
                    (int)$this->restricted_serviceid
                )];
            }
        }

        try {
            $this->load_function_info();
        } catch (\dml_missing_record_exception | \invalid_parameter_exception $exception) {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Unknown tool: ' . $name);
        }

        // Fail closed: anything not explicitly typed as read needs write scope.
        $this->currentmutating = (string)($this->function->type ?? '') !== 'read';
        $this->scope_check($this->currentmutating);
        $this->trigger_function_called($name);

        $this->execute();

        return ['result' => $this->function->returns_desc !== null
            ? external_api::clean_returnvalue($this->function->returns_desc, $this->returns)
            : $this->returns];
    }

    /**
     * Log the call in Moodle's standard logs, as core web services do.
     *
     * @param string $name Function or wrapper name.
     * @return void
     */
    protected function trigger_function_called(string $name): void {
        \core\event\webservice_function_called::create(['other' => ['function' => $name]])->trigger();
    }

    /**
     * Authenticate the bearer token and apply Moodle's request setup.
     *
     * @return void
     */
    protected function authenticate_request(): void {
        global $CFG, $SESSION;

        $this->token = $this->extract_token();
        $this->publictoken = $this->token;
        if (empty($this->token)) {
            throw new moodle_exception('invalidtoken', 'webservice');
        }

        $this->authenticate_user();
        $this->release_transport_session();

        setup_lang_from_browser();
        if (empty($CFG->lang)) {
            $CFG->lang = empty($SESSION->lang) ? 'en' : $SESSION->lang;
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

        if (
            $service->requiredcapability &&
                !has_capability($service->requiredcapability, context_system::instance(), $user)
        ) {
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

            if (
                !empty($authoriseduser->iprestriction) &&
                    !address_in_subnet(getremoteaddr(), $authoriseduser->iprestriction)
            ) {
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

        if (
            !empty($identity->resourceuri) &&
                !hash_equals((new oauth_service())->canonical_resource_uri(), (string)$identity->resourceuri)
        ) {
            throw new moodle_exception('invalidtoken', 'webservice');
        }

        if (
            $this->authmethod !== WEBSERVICE_AUTHMETHOD_SESSION_TOKEN &&
                !has_capability("webservice/{$this->wsname}:use", $this->restricted_context, $user)
        ) {
            throw new \webservice_access_exception(
                "You are not allowed to use the {$this->wsname} protocol "
                . "(missing capability: webservice/{$this->wsname}:use)"
            );
        }

        external_api::set_context_restriction($this->restricted_context);
    }

    /**
     * Send the legacy initialize result and always mint a fresh session id.
     *
     * @return void
     */
    protected function send_initialize_response(): void {
        $version = $this->negotiate_protocol_version();
        $capabilities = $this->mcprequest->params['capabilities'] ?? [];
        $sessionid = $this->create_transport_session(is_array($capabilities) ? $capabilities : []);

        $dispatcher = $this->dispatcher(call_context::ERA_LEGACY, $version, []);
        $this->send_header('MCP-Session-Id: ' . $sessionid);
        $this->send_result($dispatcher->initialize_result($version));
    }

    /**
     * Emit a JSON-RPC success response.
     *
     * @param array $result Result object.
     * @return void
     */
    protected function send_result(array $result): void {
        if (isset($result['_empty'])) {
            $result = new \stdClass();
        }
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $this->mcprequest->id,
            'result' => $result,
        ];
        $this->set_status(200);
        $this->record_transport_event($payload);
        $this->emit($this->safe_json_encode($payload));
    }

    /**
     * Emit a protocol error with its HTTP status (and scope challenge where relevant).
     *
     * @param protocol_exception $exception Error.
     * @param mixed $id Request id.
     * @return void
     */
    protected function send_protocol_error(protocol_exception $exception, mixed $id): void {
        $this->emit_json_headers();
        if ($exception->rpccode === self::INSUFFICIENT_SCOPE) {
            $this->send_header('WWW-Authenticate: ' . $this->oauth_service()->build_bearer_challenge(
                'insufficient_scope',
                'The presented access token does not grant the required scope.',
                (string)($exception->data['requiredScope'] ?? '')
            ));
        }
        $this->set_status($exception->httpstatus);

        $error = ['code' => $exception->rpccode, 'message' => $exception->getMessage()];
        if ($exception->data !== null) {
            $error['data'] = $exception->data;
        }
        if (
            in_array($exception->httpstatus, [401, 403], true) && ($auditid = $this->record_audit_event(
                $this->audit_action_name(),
                $this->functionname ?: null,
                (bool)$this->currentmutating,
                'error',
                $exception->rpccode === self::INSUFFICIENT_SCOPE ? 'insufficient_scope' : 'protocol_error',
                $exception->getMessage()
            ))
        ) {
            $error['data']['auditId'] = $auditid;
        }

        $this->emit($this->safe_json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]));
    }

    /**
     * Send a protocol-aware error response for authentication and unexpected failures.
     *
     * @param \Throwable|null $ex Optional exception.
     * @return void
     */
    protected function send_error($ex = null): void {
        $this->emit_json_headers();
        $status = $this->exception_status($ex);
        if ($status === 401) {
            $this->send_header('WWW-Authenticate: ' . $this->oauth_service()->build_bearer_challenge(
                'invalid_token',
                'Authorization is required to access this MCP server.',
                $this->oauth_default_scope()
            ));
        }
        $this->set_status($status);
        if ($status === 401) {
            $this->record_rejected_token_audit($ex);
        }

        if ($ex !== null && debugging('', DEBUG_MINIMAL)) {
            $this->log_exception_for_debug($ex);
        }

        $this->emit($this->safe_json_encode($this->generate_error($ex)));
    }

    /**
     * Audit a rejected bearer token (HTTP 401).
     *
     * Only requests that presented a token are audited: anonymous discovery probes without an Authorization header
     * would only add noise. The row names the credential and its user when the token is a known plugin credential.
     *
     * @param \Throwable|null $ex The authentication failure.
     * @return void
     */
    protected function record_rejected_token_audit(?\Throwable $ex): void {
        if (empty($this->publictoken)) {
            return;
        }

        $credential = (new \webservice_mcp\local\auth\credential_manager())->find_credential((string)$this->publictoken);
        $code = 'invalid_token';
        if ($credential && !empty($credential->validuntil) && (int)$credential->validuntil < time()) {
            $code = 'token_expired';
        } else if ($credential && !empty($credential->revoked)) {
            $code = 'token_revoked';
        }

        try {
            $this->auditlogger->record([
                'userid' => $credential ? (int)$credential->userid : null,
                'credentialid' => $credential ? (int)$credential->id : null,
                'sessionid' => $this->transportrequest['sessionid'] ?? null,
                'requestid' => $this->request_id_string(),
                'action' => 'request',
                'outcome' => 'error',
                'detailcode' => $code,
                'detail' => $ex !== null ? $this->audit_detail_message($ex) : null,
            ]);
        } catch (\Throwable $exception) {
            if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
                throw $exception;
            }
            debugging('MCP audit write failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Add structured restriction metadata to generated transport errors.
     *
     * @param mixed $ex Exception-like payload.
     * @return array
     */
    protected function generate_error($ex): array {
        $error = parent::generate_error($ex);
        if ($ex instanceof \Throwable && $this->exception_status($ex) >= 500 && !debugging('', DEBUG_DEVELOPER)) {
            // Do not leak internals (paths, SQL) on unexpected failures.
            $error['error']['message'] = 'Internal error';
            unset($error['error']['data']);
        }
        $error['error']['code'] = match ($this->exception_status($ex)) {
            401, 403 => -32001,
            400 => protocol_exception::INVALID_PARAMS,
            default => protocol_exception::INTERNAL_ERROR,
        };
        $restriction = $this->restriction_details_for_exception($ex);
        if ($restriction !== null) {
            $error['error']['data']['restriction'] = $restriction;
        }
        if (
            $this->userid && ($auditid = $this->record_audit_event(
                $this->audit_action_name(),
                $this->functionname ?: null,
                (bool)$this->currentmutating,
                'error',
                $this->audit_detail_code($ex),
                $this->audit_detail_message($ex)
            ))
        ) {
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
     * Create a new legacy transport session.
     *
     * @param array $clientcapabilities Client capabilities declared at initialize.
     * @param bool $legacysse Whether the session belongs to the deprecated HTTP+SSE transport.
     * @return string
     */
    protected function create_transport_session(array $clientcapabilities = [], bool $legacysse = false): string {
        $sessionid = $this->sessionstore->create_session([
            'userid' => (int)$this->userid,
            'contextid' => (int)$this->restricted_context->id,
            'serviceid' => (int)$this->restricted_serviceid,
            'serviceidentifier' => $this->transportidentity->restrictedservice ?? '',
            'principal' => $this->session_principal(),
            'protocolversion' => $this->negotiate_protocol_version(),
            'clientcapabilities' => $clientcapabilities,
            'legacysse' => $legacysse,
        ]);
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
        $valid = $session !== null
            && hash_equals((string)($session['principal'] ?? ''), $this->session_principal())
            && (int)($session['userid'] ?? 0) === (int)$this->userid
            && (int)($session['contextid'] ?? 0) === (int)$this->restricted_context->id
            && (int)($session['serviceid'] ?? 0) === (int)$this->restricted_serviceid;

        if (!$valid) {
            $this->send_transport_error(404, -32001, 'Unknown MCP session.', $this->mcprequest?->id ?? null);
            return false;
        }

        $requestversion = $this->transportrequest['protocolversionheader'] ?? null;
        if ($requestversion !== null && ($session['protocolversion'] ?? '') !== $requestversion) {
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
     * Terminate a legacy transport session.
     *
     * @return void
     */
    protected function handle_delete(): void {
        if (!$this->prepare_stateful_request()) {
            return;
        }
        $this->authenticate_request();
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

        $this->send_header('Cache-Control: no-store');
        $this->send_header('Pragma: no-cache');
        $this->send_header('Access-Control-Allow-Methods: ' . static::ALLOW);
        $this->send_header('Access-Control-Allow-Headers: ' . static::ALLOW_HEADERS);
        $this->send_header('Access-Control-Expose-Headers: MCP-Session-Id, WWW-Authenticate');
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
        $this->emit($this->safe_json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => $code, 'message' => $message],
            'id' => $id,
        ]));
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

        if (
            $exception instanceof restricted_context_exception
                || $exception instanceof \required_capability_exception
                || $exception instanceof \webservice_access_exception
        ) {
            return 403;
        }

        if ($exception instanceof moodle_exception) {
            return match ($exception->errorcode ?? '') {
                'invalidtoken' => 401,
                'wsaccessuserdeleted', 'wsaccessusersuspended', 'wsaccessuserunconfirmed', 'wsaccessusernologin',
                'wsaccessuserexpired', 'servicenotavailable', 'sitepolicynotagreed' => 403,
                'sitemaintenance' => 503,
                default => 500,
            };
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
            return ['category' => 'capability', 'code' => 'missing_capability', 'retryable' => false];
        }

        if ($exception instanceof restricted_context_exception) {
            return ['category' => 'context', 'code' => 'restricted_context', 'retryable' => true];
        }

        if ($exception instanceof \webservice_access_exception) {
            return ['category' => 'service', 'code' => 'webservice_access_denied', 'retryable' => false];
        }

        if ($exception instanceof moodle_exception) {
            return match ($exception->errorcode ?? '') {
                'servicerequireslogin', 'requireloginerror', 'requirelogin' =>
                    ['category' => 'authentication', 'code' => 'login_required', 'retryable' => true],
                'notingroup' => ['category' => 'group', 'code' => 'group_membership_required', 'retryable' => false],
                'nopermissions' => ['category' => 'capability', 'code' => 'permission_denied', 'retryable' => false],
                'invalidtoken' => ['category' => 'authentication', 'code' => 'invalid_token', 'retryable' => true],
                default => null,
            };
        }

        return null;
    }

    /**
     * Persist one audit event without breaking the transport on logging failures.
     *
     * @param string $action Audit action type.
     * @param string|null $toolname Tool name when applicable.
     * @param bool $mutating Whether the request mutates state.
     * @param string $outcome Event outcome.
     * @param string|null $detailcode Optional restriction or error code.
     * @param string|null $detail Optional error message (stored for non-success outcomes only).
     * @return string|null
     */
    protected function record_audit_event(
        string $action,
        ?string $toolname,
        bool $mutating,
        string $outcome,
        ?string $detailcode = null,
        ?string $detail = null
    ): ?string {
        $userid = isset($this->transportidentity->user->id) ? (int)$this->transportidentity->user->id : ($this->userid ?? null);
        if (empty($userid)) {
            // Never audit unauthenticated traffic: it lets anyone grow the table.
            return null;
        }
        $credentialid = isset($this->transportidentity->credential->id)
            ? (int)$this->transportidentity->credential->id
            : null;

        try {
            return $this->auditlogger->record([
                'userid' => $userid,
                'credentialid' => $credentialid,
                'contextid' => isset($this->restricted_context->id) ? (int)$this->restricted_context->id : null,
                'serviceid' => $this->restricted_serviceid ?? null,
                'sessionid' => $this->transportrequest['sessionid'] ?? null,
                'requestid' => $this->request_id_string(),
                'action' => $action,
                'toolname' => $toolname,
                'mutating' => $mutating,
                'outcome' => $outcome,
                'detailcode' => $detailcode,
                'detail' => $detail,
            ]);
        } catch (\Throwable $exception) {
            if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
                throw $exception;
            }
            debugging('MCP audit write failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Determine the audit action name for the current request.
     *
     * @return string
     */
    protected function audit_action_name(): string {
        $method = $this->mcprequest instanceof request ? $this->mcprequest->method : '';
        return match ($method) {
            'tools/list' => 'discover',
            'tools/call' => 'tool_call',
            default => 'request',
        };
    }

    /**
     * Whether the current tools/call target mutates state (as resolved during execution).
     *
     * @return bool
     */
    protected function current_request_is_mutating(): bool {
        return (bool)$this->currentmutating;
    }

    /**
     * Handle HEAD probes without emitting a response body.
     *
     * @return void
     */
    protected function handle_head_request(): void {
        $this->emit_json_headers(false);
        try {
            $this->authenticate_request();
            $this->set_status(204);
        } catch (\Throwable $exception) {
            $status = $this->exception_status($exception);
            if ($status === 401) {
                $this->send_header('WWW-Authenticate: ' . $this->oauth_service()->build_bearer_challenge(
                    'invalid_token',
                    'Authorization is required to access this MCP server.',
                    $this->oauth_default_scope()
                ));
                $this->record_rejected_token_audit($exception);
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
        if (!($this->mcprequest instanceof request) || $this->mcprequest->id === null) {
            return null;
        }
        return (string)$this->mcprequest->id;
    }

    /**
     * Extract the error message for audit storage, as production users see it.
     *
     * Moodle exceptions are rebuilt from their language string, so debug information (which can echo argument
     * values) is never stored, whatever the debugging level.
     *
     * @param mixed $exception Exception-like payload.
     * @return string|null
     */
    protected function audit_detail_message(mixed $exception): ?string {
        if (
            $exception instanceof moodle_exception && $exception->errorcode !== '' &&
                get_string_manager()->string_exists($exception->errorcode, (string)$exception->module)
        ) {
            return get_string($exception->errorcode, (string)$exception->module, $exception->a);
        }

        return $exception instanceof \Throwable ? $exception->getMessage() : null;
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
     * Keep responses for replay only on deprecated HTTP+SSE sessions, which are the only reader.
     *
     * @param array $payload Response payload.
     * @return void
     */
    protected function record_transport_event(array $payload): void {
        if (empty($this->transportsession['legacysse']) || empty($this->transportrequest['sessionid'])) {
            return;
        }

        $this->replaystore->append_event((string)$this->transportrequest['sessionid'], [
            'type' => 'message',
            'payload' => $payload,
        ]);
    }

    /**
     * Negotiate the legacy protocol version for the current initialize request.
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

        // Unknown versions get our newest legacy revision; the client decides whether it can continue.
        return dispatcher::LEGACY_VERSIONS[0];
    }

    /**
     * Stable principal sessions bind to: the credential (survives token refresh) or the raw token.
     *
     * @return string
     */
    protected function session_principal(): string {
        if (!empty($this->transportidentity->familyid)) {
            // The family survives OAuth refresh-token rotation, so sessions do too.
            return hash('sha256', 'family:' . $this->transportidentity->familyid);
        }
        return hash('sha256', 'token:' . (string)($this->publictoken ?? $this->token ?? ''));
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
     * Throw when the token lacks the read or write scope.
     *
     * @param bool $write Whether write scope is needed.
     * @return void
     * @throws protocol_exception
     */
    protected function scope_check(bool $write): void {
        if (!$this->oauth_scope_enforced()) {
            return;
        }

        $required = $write ? oauth_service::SCOPE_WRITE : oauth_service::SCOPE_READ;
        if (!oauth_service::scope_contains((string)($this->transportidentity->scope ?? ''), $required)) {
            throw new protocol_exception(
                self::INSUFFICIENT_SCOPE,
                'Insufficient OAuth scope.',
                403,
                ['requiredScope' => $required]
            );
        }
    }
}
