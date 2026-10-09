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

namespace webservice_mcp\local\mcp;

use webservice_mcp\local\files\tools as file_tools;
use webservice_mcp\local\signer;
use webservice_mcp\local\tool_provider;

/**
 * Era-aware MCP method router.
 *
 * Builds result objects for every server-side MCP method. The transport owns HTTP,
 * authentication and sessions; this class owns protocol semantics.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dispatcher {
    /** Stateless revision served natively. */
    public const MODERN_VERSIONS = ['2026-07-28'];

    /** Initialize-based revisions served through sessions. */
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /** Server name reported in serverInfo. */
    public const SERVER_NAME = 'moodle';

    /** Page size for paginated lists. */
    private const PAGE_SIZE = 200;

    /** Function-name words that make a call destructive. */
    private const DESTRUCTIVE_PATTERN = '/(^|_)(delete|remove|revoke|unenrol|unassign|reset|purge|cancel)(_|s_|s$|$)/';

    /** @var call_context */
    private call_context $ctx;

    /** @var \Closure fn(string $name, array $arguments): array, executes wrapper and native tools. */
    private \Closure $toolexecutor;

    /**
     * Constructor.
     *
     * @param call_context $ctx Request context.
     * @param \Closure|null $toolexecutor Executor for non-file tools; returns the structured payload or throws.
     */
    public function __construct(call_context $ctx, ?\Closure $toolexecutor = null) {
        $this->ctx = $ctx;
        $this->toolexecutor = $toolexecutor ?? static function (): array {
            throw new protocol_exception(protocol_exception::INTERNAL_ERROR, 'No tool executor configured.');
        };
    }

    /**
     * Every supported protocol version, newest first.
     *
     * @return string[]
     */
    public static function supported_versions(): array {
        return array_merge(self::MODERN_VERSIONS, self::LEGACY_VERSIONS);
    }

    /**
     * Whether a method name is one this server implements for the context's era.
     *
     * @param string $method JSON-RPC method.
     * @return bool
     */
    public function handles(string $method): bool {
        $common = ['tools/list', 'tools/call', 'resources/list', 'resources/templates/list', 'resources/read',
            'prompts/list', 'prompts/get', 'completion/complete', 'server/discover',
            'skills/list', 'skills/get', 'resources/directory/read'];
        $legacy = ['ping', 'logging/setLevel', 'tasks/get', 'tasks/result', 'tasks/list', 'tasks/cancel'];
        $modern = ['tasks/get', 'tasks/update', 'tasks/cancel'];
        return in_array($method, $common, true)
            || in_array($method, $this->ctx->era === call_context::ERA_LEGACY ? $legacy : $modern, true);
    }

    /**
     * Dispatch one request and return its era-finalised result.
     *
     * @param string $method JSON-RPC method.
     * @param array $params Request params.
     * @return array
     * @throws protocol_exception
     */
    public function dispatch(string $method, array $params): array {
        if (!$this->handles($method)) {
            throw new protocol_exception(
                protocol_exception::METHOD_NOT_FOUND,
                'Method not found: ' . $method,
                $this->ctx->era === call_context::ERA_MODERN ? 404 : 200
            );
        }

        if ($method !== 'server/discover' && $method !== 'ping' && $this->ctx->user === null) {
            throw new protocol_exception(protocol_exception::INVALID_REQUEST, 'Authentication required.', 401);
        }

        $result = match ($method) {
            'server/discover' => $this->discover(),
            'ping', 'logging/setLevel' => [],
            'tools/list' => $this->list_tools($params),
            'tools/call' => $this->call_tool($params),
            'resources/list' => $this->scoped_read(fn() => (new resources($this->ctx))->list($params['cursor'] ?? null)),
            'resources/templates/list' => $this->scoped_read(fn() => (new resources($this->ctx))->templates()),
            'resources/read' => $this->scoped_read(fn() => (new resources($this->ctx))->read((string)($params['uri'] ?? ''))),
            'prompts/list' => $this->scoped_read(fn() => (new prompts($this->ctx))->list()),
            'prompts/get' => $this->scoped_read(fn() => (new prompts($this->ctx))->get(
                (string)($params['name'] ?? ''),
                is_array($params['arguments'] ?? null) ? $params['arguments'] : []
            )),
            'completion/complete' => $this->scoped_read(fn() => (new completions($this->ctx))->complete($params)),
            'skills/list' => $this->scoped_read(fn() => skills::list()),
            'skills/get' => $this->scoped_read(fn() => skills::get((string)($params['uri'] ?? ''))),
            'resources/directory/read' => $this->scoped_read(fn() => skills::read_directory((string)($params['uri'] ?? ''))),
            'tasks/get' => $this->scoped_read(fn() => (new tasks($this->ctx))->get((string)($params['taskId'] ?? ''))),
            'tasks/result' => $this->scoped_read(fn() => (new tasks($this->ctx))->result((string)($params['taskId'] ?? ''))),
            'tasks/list' => $this->scoped_read(fn() => (new tasks($this->ctx))->list($params['cursor'] ?? null)),
            'tasks/cancel' => $this->scoped_read(fn() => (new tasks($this->ctx))->cancel((string)($params['taskId'] ?? ''))),
            'tasks/update' => $this->scoped_read(fn() => (new tasks($this->ctx))->update((string)($params['taskId'] ?? ''))),
        };

        return $this->finalise($method, $result);
    }

    /**
     * Build the legacy initialize result.
     *
     * @param string $version Negotiated legacy version.
     * @return array
     */
    public function initialize_result(string $version): array {
        return [
            'protocolVersion' => $version,
            'capabilities' => $this->capabilities(),
            'serverInfo' => $this->server_info(),
            'instructions' => $this->instructions(),
        ];
    }

    /**
     * Server capabilities shared by both eras.
     *
     * @return array
     */
    public function capabilities(): array {
        $capabilities = [
            'tools' => ['listChanged' => false],
            'resources' => ['subscribe' => false, 'listChanged' => false],
            'prompts' => ['listChanged' => false],
            'completions' => (object)[],
            'logging' => (object)[],
            'extensions' => [
                apps::EXTENSION => (object)[],
                skills::EXTENSION => ['directoryRead' => true],
            ],
        ];
        if ($this->ctx->era === call_context::ERA_MODERN) {
            $capabilities['extensions'][tasks::EXTENSION] = (object)[];
        } else {
            $capabilities['tasks'] = [
                'list' => (object)[],
                'cancel' => (object)[],
                'requests' => ['tools' => ['call' => (object)[]]],
            ];
        }
        return $capabilities;
    }

    /**
     * server/discover result.
     *
     * @return array
     */
    private function discover(): array {
        return [
            'supportedVersions' => self::supported_versions(),
            'capabilities' => $this->capabilities(),
            'instructions' => $this->instructions(),
            'ttlMs' => 3600000,
            'cacheScope' => 'public',
        ];
    }

    /**
     * tools/list over harvested functions, wrappers and file tools.
     *
     * @param array $params Request params.
     * @return array
     */
    private function list_tools(array $params): array {
        $this->ctx->require_scope(false);

        $listed = tool_provider::list_tools_for_service_ids(
            $this->ctx->serviceid ? [(int)$this->ctx->serviceid] : [],
            [
                'limit' => 2000,
                'group' => $params['group'] ?? null,
                'restrictedcontext' => $this->ctx->restrictedcontext,
                'user' => $this->ctx->user,
                'connector_mode' => $this->ctx->connectormode,
                'allow_wrappers' => $this->ctx->connector,
            ]
        );

        $tools = array_merge(apps::tools(), array_map([$this, 'project_tool'], $listed['tools']));
        if ($this->ctx->connector) {
            $tools = array_merge(file_tools::describe($this->ctx), $tools);
        }

        if ($this->ctx->era === call_context::ERA_LEGACY) {
            // Any tool can run as a cron-backed task when the client asks (2025-11-25 tasks).
            foreach ($tools as &$tool) {
                $tool['execution'] = ['taskSupport' => 'optional'];
            }
            unset($tool);
        }

        $page = $this->paginate($tools, $params['cursor'] ?? null);
        $result = ['tools' => $page['items'], 'ttlMs' => 300000, 'cacheScope' => 'private'];
        if ($page['next'] !== null) {
            $result['nextCursor'] = $page['next'];
        }
        return $result;
    }

    /**
     * Convert an internal tool description into its wire shape.
     *
     * Internal discovery metadata moves under a namespaced _meta key; core gateway tools
     * are marked always-load so clients with deferred tool search keep them in context.
     *
     * @param array $tool Internal tool description.
     * @return array
     */
    private function project_tool(array $tool): array {
        $xmoodle = $tool['x-moodle'] ?? [];
        unset($tool['x-moodle']);

        $tool['annotations'] = ($tool['annotations'] ?? []) + ['openWorldHint' => false];
        $tool['_meta'] = ($tool['_meta'] ?? []) + array_filter([
            'org.moodle/component' => $xmoodle['component'] ?? null,
            'org.moodle/domain' => $xmoodle['domain'] ?? null,
            'org.moodle/mutability' => $xmoodle['mutability'] ?? null,
        ]);
        $gateway = ['wrapper_moodle_api_search', 'wrapper_moodle_api_describe', 'wrapper_moodle_api_execute'];
        if (in_array($tool['name'], $gateway, true)) {
            $tool['_meta']['anthropic/alwaysLoad'] = true;
            $tool['_meta']['anthropic/maxResultSizeChars'] = 200000;
        }
        if ($tool['_meta'] === []) {
            unset($tool['_meta']);
        }
        return $tool;
    }

    /**
     * tools/call: route to file tools or the transport executor, and map failures to isError results.
     *
     * @param array $params Request params.
     * @return array
     */
    private function call_tool(array $params): array {
        $name = (string)($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if ($name === '') {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Missing tool name.');
        }

        if ($confirmation = $this->confirmation_gate($name, $arguments, $params)) {
            return $confirmation;
        }

        // Connector-only tools are refused before the task branch, so queueing a task can't bypass this.
        if (file_tools::handles($name) && !$this->ctx->connector) {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Unknown tool: ' . $name);
        }

        $tasks = new tasks($this->ctx);
        if ($tasks->should_run_as_task($name, $arguments, $params)) {
            $this->ctx->require_scope($this->tool_is_mutating($name, $arguments));
            return $tasks->create($name, $arguments, $params);
        }

        try {
            if (apps::handles($name)) {
                return apps::execute($arguments, $this->ctx);
            }
            if (file_tools::handles($name)) {
                $this->ctx->require_scope(file_tools::is_mutating($name));
                return file_tools::execute($name, $arguments, $this->ctx);
            }

            $payload = ($this->toolexecutor)($name, $arguments);
            return self::structured_result($payload);
        } catch (\Throwable $e) {
            // A function that threw inside a delegated transaction leaves it open; close it before anything else writes.
            abort_all_db_transactions();
            if ($e instanceof protocol_exception) {
                throw $e;
            }
            return self::error_result($e);
        }
    }

    /**
     * Run a tool outside HTTP (task execution in cron) and return its CallToolResult.
     *
     * @param call_context $ctx Context rebuilt from the task.
     * @param string $name Tool name.
     * @param array $arguments Arguments.
     * @return array
     */
    public static function run_tool(call_context $ctx, string $name, array $arguments): array {
        // Cron starts with no restriction (= system scope); apply the credential's before anything runs.
        \core_external\external_api::set_context_restriction($ctx->restrictedcontext);
        try {
            if (apps::handles($name)) {
                return apps::execute($arguments, $ctx);
            }
            if (file_tools::handles($name)) {
                if (!$ctx->connector) {
                    throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Unknown tool: ' . $name);
                }
                return file_tools::execute($name, $arguments, $ctx);
            }
            return self::structured_result(tool_runner::run($name, $arguments, $ctx));
        } catch (\Throwable $e) {
            abort_all_db_transactions();
            if ($e instanceof protocol_exception) {
                throw $e;
            }
            return self::error_result($e);
        }
    }

    /**
     * Whether a tool call needs write scope.
     *
     * @param string $name Tool name.
     * @param array $arguments Arguments.
     * @return bool
     */
    private function tool_is_mutating(string $name, array $arguments): bool {
        if (apps::handles($name)) {
            return false;
        }
        if (file_tools::handles($name)) {
            return file_tools::is_mutating($name);
        }
        $wrappers = new \webservice_mcp\local\wrapper\manager();
        if ($this->ctx->connector && $wrappers->find($name) !== null) {
            return $wrappers->is_mutating($name, $arguments);
        }
        try {
            return (string)(\core_external\external_api::external_function_info($name)->type ?? '') !== 'read';
        } catch (\Throwable $e) {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Unknown tool: ' . $name);
        }
    }

    /**
     * Ask the user to confirm destructive calls through MRTR form elicitation (modern clients only).
     *
     * @param string $name Tool name.
     * @param array $arguments Tool arguments.
     * @param array $params Full request params (inputResponses, requestState).
     * @return array|null An InputRequiredResult or error result to return, or null to proceed.
     */
    private function confirmation_gate(string $name, array $arguments, array $params): ?array {
        if (
            $this->ctx->era !== call_context::ERA_MODERN || !$this->ctx->supports_form_elicitation()
                || empty(get_config('webservice_mcp', 'confirmdestructive'))
        ) {
            return null;
        }

        $target = $name === 'wrapper_moodle_api_execute' ? (string)($arguments['functionname'] ?? '') : $name;
        if (!preg_match(self::DESTRUCTIVE_PATTERN, $target)) {
            return null;
        }

        $digest = hash('sha256', $name . json_encode($arguments));
        $claims = ['u' => (int)$this->ctx->user->id, 'f' => (string)($this->ctx->credentialid ?? ''), 'd' => $digest];

        if (!empty($params['requestState'])) {
            $state = signer::verify('mrtr-confirm', (string)$params['requestState']);
            if (
                $state === null || $state['u'] !== $claims['u'] || ($state['f'] ?? null) !== $claims['f']
                    || $state['d'] !== $digest || !self::consume_once((string)$params['requestState'], 900)
            ) {
                throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Invalid, expired or already used requestState.');
            }
            $response = $params['inputResponses']['confirm'] ?? [];
            if (($response['action'] ?? '') === 'accept' && !empty($response['content']['confirm'])) {
                return null;
            }
            return self::error_result(
                new \moodle_exception('error', 'moodle', '', null, 'Cancelled: the user did not confirm.'),
                'The user declined to confirm ' . $target . '. Do not retry unless they ask.'
            );
        }

        return [
            'resultType' => 'input_required',
            'inputRequests' => [
                'confirm' => [
                    'method' => 'elicitation/create',
                    'params' => [
                        'mode' => 'form',
                        'message' => "Confirm destructive Moodle action: {$target}\n" .
                            \core_text::substr(json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, 1500),
                        'requestedSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'confirm' => [
                                    'type' => 'boolean',
                                    'title' => 'Yes, perform this action',
                                    'default' => false,
                                ],
                            ],
                            'required' => ['confirm'],
                        ],
                    ],
                ],
            ],
            'requestState' => signer::sign('mrtr-confirm', $claims, 900),
        ];
    }

    /**
     * Record a single-use value; false when it was already used (durable, survives cache purges).
     *
     * @param string $value Value to consume.
     * @param int $ttl Seconds the value stays valid.
     * @return bool
     */
    private static function consume_once(string $value, int $ttl): bool {
        global $DB;
        $key = hash('sha256', "mrtr\n" . $value);
        if ($DB->record_exists('webservice_mcp_jti', ['keyhash' => $key])) {
            return false;
        }
        try {
            $DB->insert_record('webservice_mcp_jti', (object)['keyhash' => $key, 'expiresat' => time() + $ttl]);
        } catch (\dml_write_exception $e) {
            // A concurrent retry won the unique index.
            return false;
        }
        return true;
    }

    /**
     * Wrap a structured payload as a CallToolResult.
     *
     * @param array $payload Structured payload, normally ['result' => mixed].
     * @return array
     */
    public static function structured_result(array $payload): array {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]],
            'structuredContent' => $payload,
        ];
    }

    /**
     * Turn an execution failure into an isError CallToolResult the model can act on.
     *
     * @param \Throwable $e Failure.
     * @param string|null $message Optional message override.
     * @return array
     */
    public static function error_result(\Throwable $e, ?string $message = null): array {
        $errorcode = $e instanceof \moodle_exception
            ? (string)$e->errorcode
            : strtolower((new \ReflectionClass($e))->getShortName());
        // Gateway failures wrap the real Moodle error; surface its code so the model can react to it.
        if ($e instanceof \moodle_exception && is_object($e->a) && !empty($e->a->errorcode)) {
            $errorcode = (string)$e->a->errorcode;
        }
        // PHP errors carry server paths and line numbers; only Moodle exceptions have user-facing messages.
        $text = $message ?? ($e instanceof \moodle_exception || debugging('', DEBUG_DEVELOPER)
            ? $e->getMessage() : 'Internal error.');
        if ($e instanceof \moodle_exception && !empty($e->debuginfo) && debugging('', DEBUG_DEVELOPER)) {
            $text .= ' (' . $e->debuginfo . ')';
        }
        return [
            'isError' => true,
            'content' => [['type' => 'text', 'text' => "Error [{$errorcode}]: {$text}"]],
            '_meta' => ['org.moodle/errorcode' => $errorcode],
        ];
    }

    /**
     * Run a read-only feature handler after the read-scope check.
     *
     * @param \Closure $handler Handler.
     * @return array
     */
    private function scoped_read(\Closure $handler): array {
        $this->ctx->require_scope(false);
        return $handler();
    }

    /**
     * Add or strip era-specific result fields.
     *
     * @param string $method Method.
     * @param array $result Raw result.
     * @return array
     */
    private function finalise(string $method, array $result): array {
        if ($this->ctx->era === call_context::ERA_MODERN) {
            $result['resultType'] ??= 'complete';
            $result['_meta'] = ($result['_meta'] ?? []) + ['io.modelcontextprotocol/serverInfo' => $this->server_info()];
            return $result;
        }

        unset($result['resultType'], $result['ttlMs'], $result['cacheScope']);
        return $result === [] ? ['_empty' => true] : $result;
    }

    /**
     * Implementation info.
     *
     * @return array
     */
    public function server_info(): array {
        global $CFG, $SITE;
        $info = [
            'name' => self::SERVER_NAME,
            'title' => format_string($SITE->fullname ?? 'Moodle'),
            'version' => server_card::release(),
            'description' => 'Moodle LMS: courses, activities, grades, files, messaging and administration, '
                . 'limited to what the signed-in user may do.',
            'websiteUrl' => $CFG->wwwroot,
        ];
        $logo = get_config('core_admin', 'logocompact');
        if (!empty($logo)) {
            $info['icons'] = [[
                'src' => \moodle_url::make_pluginfile_url(
                    \context_system::instance()->id,
                    'core_admin',
                    'logocompact',
                    0,
                    '/',
                    ltrim((string)$logo, '/')
                )->out(false),
            ]];
        }
        return $info;
    }

    /**
     * Server instructions (clients truncate at about 2048 characters).
     *
     * @return string
     */
    public function instructions(): string {
        return implode("\n\n", [
            'Moodle LMS connector. Every action runs as the signed-in Moodle user and is limited to their real '
            . 'permissions; a permission error means the user may not do that, so do not retry with other tools.',
            'Finding functionality: Moodle has 800+ functions. Use wrapper_moodle_api_search (keywords, e.g. '
            . '"assign grade", "forum discussion", "enrol users") then wrapper_moodle_api_describe for the exact '
            . 'parameter schema, then wrapper_moodle_api_execute. Typed wrapper_* tools cover course editing, '
            . 'question bank, gradebook and badges directly.',
            'Files: file_list browses any file area the user can see (course, activity, user private, backups). '
            . 'file_read returns small files inline (text, images, or base64). For large files call '
            . 'file_get_download_url and fetch it with an HTTP client (e.g. curl -o out "<url>"); links expire. To '
            . 'upload: small files via file_upload (base64); large files via file_create_upload_url then curl -T '
            . 'path "<url>". Uploads land in a draft area and return a draftitemid that any Moodle function '
            . 'accepting draft files takes (e.g. mod_assign_save_submission files_filemanager, '
            . 'mod_forum_add_discussion attachmentsid, core_user_add_user_private_files, wrapper_course_add_module '
            . 'options.files for resource/folder). file_save_draft moves draft files into a writable area.',
            'Resources: moodle://courses, moodle://course/{id}, moodle://module/{cmid}, moodle://user/me and '
            . 'moodle://file/... can be read directly. Prompts and skills (skill://moodle-*) provide ready-made '
            . 'workflows: file transfer, grading, course building, reporting, bulk admin.',
            'Prefer IDs from previous results over guessing.',
        ]);
    }

    /**
     * Cursor pagination over an in-memory list.
     *
     * @param array $items Items.
     * @param mixed $cursor Opaque cursor (stringified offset).
     * @return array{items: array, next: ?string}
     */
    private function paginate(array $items, mixed $cursor): array {
        $offset = 0;
        if ($cursor !== null && $cursor !== '') {
            if (!is_string($cursor) || !ctype_digit($cursor)) {
                throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Invalid cursor.');
            }
            $offset = (int)$cursor;
        }
        $slice = array_slice($items, $offset, self::PAGE_SIZE);
        $next = ($offset + self::PAGE_SIZE) < count($items) ? (string)($offset + self::PAGE_SIZE) : null;
        return ['items' => $slice, 'next' => $next];
    }
}
