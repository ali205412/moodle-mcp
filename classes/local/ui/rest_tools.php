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
 * Routed REST API tools: describe and call Moodle's own /api/rest/v2 routes as the signed-in user.
 *
 * @package    webservice_mcp
 * @copyright  2026 Aspire School
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace webservice_mcp\local\ui;

use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * Routed REST API tools (Moodle 4.5+ router).
 *
 * The route list comes from Moodle's own OpenAPI generator (\core\router\apidocs, also served at
 * /r.php/api/rest/v2/openapi.json). Calls go through the session bridge: the router's API middleware accepts a
 * Moodle session cookie when no Authorization header is sent, so routes run as the connector's user with their
 * own permission checks. In-process dispatch is not used because the router would start a fresh PHP session for
 * a cookie-less request and replace the web service user with the guest.
 *
 * @package    webservice_mcp
 */
final class rest_tools {
    /** @var int Characters of response text returned. */
    private const MAX_CHARS = 150000;

    /** @var int Routes listed per describe call. */
    private const MAX_ROUTES = 200;

    /** @var array|null OpenAPI document of this request. */
    private static ?array $spec = null;

    /**
     * Whether this Moodle has the routed REST API.
     *
     * @return bool
     */
    public static function supported(): bool {
        return class_exists(\core\router\apidocs::class) && method_exists(\core\url::class, 'routed_path');
    }

    /**
     * Tool definitions (none before the router exists).
     *
     * @return array
     */
    public static function definitions(): array {
        if (!self::supported()) {
            return [];
        }
        $path = ['type' => 'string', 'maxLength' => 1024,
            'description' => 'Route path relative to the REST base, e.g. /user/current/preferences.'];
        return [
            'moodle_rest_describe' => [
                'title' => 'Describe Moodle REST API routes',
                'description' => 'List or search the routed REST API of this Moodle site (/api/rest/v2, from Moodle\'s own '
                    . 'OpenAPI description): method, path, summary and component of each route. Give path (and '
                    . 'optionally method) to get its full parameters, request body schema and responses. Path '
                    . 'parameters such as {user} accept "current" for the signed-in user. Call routes with '
                    . 'moodle_rest_call; Moodle checks the user\'s permissions when the route runs.',
                'properties' => [
                    'search' => ['type' => 'string', 'maxLength' => 255,
                        'description' => 'Words matched against path, summary, description and component.'],
                    'path' => $path,
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                ],
                'required' => [],
                'mutating' => false,
            ],
            'moodle_rest_call' => [
                'title' => 'Call a Moodle REST API route',
                'description' => 'Call a route of this Moodle site\'s REST API (/api/rest/v2) as the signed-in user, with '
                    . 'their permissions. Find routes and their parameters with moodle_rest_describe. Fill path '
                    . 'parameters into the path (e.g. /user/current/preferences); query parameters go in query and the '
                    . 'JSON request body in body. GET only reads; POST, PUT, PATCH and DELETE change data and need '
                    . 'write access.',
                'properties' => [
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                        'description' => 'HTTP method (default GET).'],
                    'path' => $path,
                    'query' => ['type' => 'object', 'additionalProperties' => true,
                        'description' => 'Query parameters (name => value).'],
                    'body' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'JSON request body.'],
                ],
                'required' => ['path'],
                // Annotated as mutating; the scope needed depends on the method (tools::is_mutating()).
                'mutating' => true,
            ],
        ];
    }

    /**
     * Whether a tool name belongs to these tools.
     *
     * @param string $name Tool name.
     * @return bool
     */
    public static function handles(string $name): bool {
        return array_key_exists($name, self::definitions());
    }

    /**
     * Run a REST tool and return a CallToolResult.
     *
     * @param string $name Tool name.
     * @param array $args Arguments.
     * @param call_context $ctx Request context (bridge availability already checked).
     * @param session_bridge $bridge Session bridge.
     * @return array
     */
    public static function execute(string $name, array $args, call_context $ctx, session_bridge $bridge): array {
        if ($name === 'moodle_rest_describe') {
            return self::describe_routes($args);
        }

        $method = strtoupper(trim((string)($args['method'] ?? 'GET')));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new transfer_exception(400, 'invalidparameter', 'method must be GET, POST, PUT, PATCH or DELETE.');
        }
        $ctx->require_scope($method !== 'GET');
        $path = self::path_arg($args, true);
        $url = self::base_url() . $path;
        if (!empty($args['query'])) {
            $url .= '?' . http_build_query((array)$args['query'], '', '&', PHP_QUERY_RFC3986);
        }
        $body = '';
        if (isset($args['body'])) {
            if ($method === 'GET') {
                throw new transfer_exception(400, 'invalidparameter', 'GET requests take no body; use query.');
            }
            $body = $args['body'] === [] ? '{}' : json_encode($args['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $response = $bridge->fetch($ctx, $method, $url, $body);
        $text = (string)$response['body'];
        $payload = [
            'method' => $method,
            'path' => $path,
            'status' => (int)$response['status'],
            'contenttype' => (string)$response['contenttype'],
        ];
        $data = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && strlen($text) <= self::MAX_CHARS) {
            $payload['data'] = $data;
        } else {
            $payload['text'] = \core_text::substr($text, 0, self::MAX_CHARS);
            if (\core_text::strlen($text) > self::MAX_CHARS) {
                $payload['truncated'] = true;
            }
        }
        $summary = "{$method} {$path} -> HTTP {$payload['status']}\n"
            . (array_key_exists('data', $payload) ? json_encode($payload['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE) : $payload['text']);
        return self::wrap($payload, $summary, "{$method} {$path}");
    }

    /**
     * List, search or detail routes.
     *
     * @param array $args Arguments.
     * @return array
     */
    private static function describe_routes(array $args): array {
        $spec = self::spec();
        $base = self::base_url();
        $path = isset($args['path']) && trim((string)$args['path']) !== '' ? self::path_arg($args, false) : null;
        $method = strtolower(trim((string)($args['method'] ?? '')));

        if ($path !== null) {
            $operations = [];
            foreach ((array)($spec['paths'][$path] ?? []) as $verb => $operation) {
                if ($method === '' || $verb === $method) {
                    $operations[] = ['method' => strtoupper($verb), 'path' => $path]
                        + self::resolve_refs($operation, $spec, 8);
                }
            }
            if (!$operations) {
                throw new transfer_exception(404, 'restroutenotfound', "No route {$path}"
                    . ($method !== '' ? ' for ' . strtoupper($method) : '')
                    . '. List routes with moodle_rest_describe without path; fill path parameters only when calling.');
            }
            $payload = ['base' => $base, 'routes' => $operations];
            $text = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return self::wrap($payload, $text, $path);
        }

        $words = preg_split('/\s+/', \core_text::strtolower(trim((string)($args['search'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
        $routes = [];
        foreach ((array)($spec['paths'] ?? []) as $routepath => $operations) {
            foreach ((array)$operations as $verb => $operation) {
                if ($method !== '' && $verb !== $method) {
                    continue;
                }
                $route = array_filter([
                    'method' => strtoupper((string)$verb),
                    'path' => (string)$routepath,
                    'summary' => (string)($operation['summary'] ?? ''),
                    'component' => (string)($operation['tags'][0] ?? ''),
                    'deprecated' => !empty($operation['deprecated']),
                    'hasbody' => isset($operation['requestBody']),
                ]);
                $haystack = \core_text::strtolower(implode(' ', [$routepath, $operation['summary'] ?? '',
                    $operation['description'] ?? '', implode(' ', (array)($operation['tags'] ?? []))]));
                foreach ($words as $word) {
                    if (!str_contains($haystack, $word)) {
                        continue 2;
                    }
                }
                $routes[] = $route;
            }
        }
        $payload = ['base' => $base, 'total' => count($routes), 'routes' => array_slice($routes, 0, self::MAX_ROUTES)];
        if (count($routes) > self::MAX_ROUTES) {
            $payload['truncated'] = true;
        }
        $lines = ["REST base: {$base}", count($routes) . ' route(s).'];
        foreach ($payload['routes'] as $route) {
            $lines[] = "{$route['method']} {$route['path']}" . (!empty($route['summary']) ? " - {$route['summary']}" : '');
        }
        return self::wrap($payload, implode("\n", $lines), 'describe');
    }

    /**
     * The OpenAPI document, generated in-process by Moodle (once per request).
     *
     * @return array
     */
    private static function spec(): array {
        if (self::$spec === null) {
            $response = (new \core\router\apidocs())->openapi_docs(new \GuzzleHttp\Psr7\Response());
            $spec = json_decode((string)$response->getBody(), true);
            if (!is_array($spec)) {
                throw new transfer_exception(500, 'restspecunavailable', 'Moodle did not produce its OpenAPI description.');
            }
            self::$spec = $spec;
        }
        return self::$spec;
    }

    /**
     * Inline local "#/..." references, to a limited depth.
     *
     * @param mixed $node Node.
     * @param array $spec Document.
     * @param int $depth Remaining depth.
     * @return mixed
     */
    private static function resolve_refs(mixed $node, array $spec, int $depth): mixed {
        if (!is_array($node)) {
            return $node;
        }
        if (isset($node['$ref']) && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/') && $depth > 0) {
            $target = $spec;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (!is_array($target) || !array_key_exists($segment, $target)) {
                    return $node;
                }
                $target = $target[$segment];
            }
            return self::resolve_refs($target, $spec, $depth - 1);
        }
        return array_map(fn($child) => self::resolve_refs($child, $spec, $depth), $node);
    }

    /**
     * Absolute URL of the REST base (with /r.php unless the site routes it).
     *
     * @return string
     */
    private static function base_url(): string {
        return \core\url::routed_path(\core\router\route_loader_interface::ROUTE_GROUP_API)->out(false);
    }

    /**
     * Validate the path argument and return it relative to the REST base.
     *
     * @param array $args Arguments.
     * @param bool $required Whether the path must be given.
     * @return string
     */
    private static function path_arg(array $args, bool $required): string {
        $path = trim((string)($args['path'] ?? ''));
        $prefix = \core\router\route_loader_interface::ROUTE_GROUP_API;
        $at = strpos($path, $prefix . '/');
        if ($at !== false) {
            $path = substr($path, $at + strlen($prefix));
        }
        if (($required && $path === '') || ($path !== '' && (!str_starts_with($path, '/') || strpbrk($path, '?#') !== false))) {
            throw new transfer_exception(400, 'invalidparameter', 'path must be a route path such as '
                . '/user/current/preferences, without query string (use query).');
        }
        return $path;
    }

    /**
     * Build a CallToolResult.
     *
     * @param array $payload Structured content.
     * @param string $text Text content.
     * @param string $audit Audit detail (never the query string).
     * @return array
     */
    private static function wrap(array $payload, string $text, string $audit): array {
        return ['content' => [['type' => 'text', 'text' => \core_text::substr($text, 0, self::MAX_CHARS)]],
            'structuredContent' => $payload, '_meta' => ['org.moodle/auditdetail' => \core_text::substr($audit, 0, 255)]];
    }
}
