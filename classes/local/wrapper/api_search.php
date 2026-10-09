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

namespace webservice_mcp\local\wrapper;

/**
 * Ranking and projection of visible catalog entries for wrapper_moodle_api_search/describe.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api_search {
    /** Maximum description length in search hits. */
    private const SNIPPET_LENGTH = 300;

    /** Hits include exampleArgs and requiredParams when the limit is at most this. */
    public const DETAIL_LIMIT = 10;

    /**
     * Search visible entries.
     *
     * Matching is case-insensitive over name, component, description, required capabilities and parameter names.
     * An empty query with no filters returns a domain/component summary instead of hits.
     *
     * @param array $entries Visible entries keyed by name.
     * @param string $query Keywords.
     * @param int $limit Maximum hits (already clamped).
     * @param string $component Optional component or function-name prefix filter (e.g. "mod_forum", "core_user").
     * @param string $type Optional type filter: read or write.
     * @return array
     */
    public static function search(array $entries, string $query, int $limit, string $component = '', string $type = ''): array {
        $component = strtolower(trim($component));
        // Core functions report component "moodle", so the filter also matches function-name prefixes ("core_user").
        $entries = array_filter($entries, static fn(array $entry): bool =>
            ($component === '' || str_starts_with(strtolower($entry['component']), $component)
                || str_starts_with(strtolower($entry['name']), $component))
            && ($type === '' || self::type_of($entry) === $type));

        if ($query === '' && $component === '' && $type === '') {
            return [
                'query' => '',
                'total' => count($entries),
                'results' => [],
                'groups' => self::summary($entries),
                'hint' => 'Search with keywords such as "course contents", "assign submissions", a capability such as '
                    . '"mod/forum:replypost", a parameter name, or filter by component (e.g. "mod_forum") or type.',
            ];
        }

        $scored = [];
        if ($query === '') {
            foreach ($entries as $entry) {
                $scored[] = ['matched' => 0, 'score' => 0, 'entry' => $entry];
            }
        } else {
            $tokens = self::tokens($query);
            foreach ($entries as $entry) {
                [$matched, $score] = self::score($entry, $tokens, strtolower($query));
                if ($matched > 0) {
                    $scored[] = ['matched' => $matched, 'score' => $score, 'entry' => $entry];
                }
            }
        }
        // Functions the user likely has the declared capabilities for rank first; the rest stay callable.
        usort($scored, static fn(array $a, array $b): int =>
            [self::likely($b['entry']), $b['matched'], $b['score'], $a['entry']['name']]
            <=> [self::likely($a['entry']), $a['matched'], $a['score'], $b['entry']['name']]);

        $detailed = $limit <= self::DETAIL_LIMIT;
        $results = array_map(
            static fn(array $hit): array => self::hit($hit['entry'], $detailed),
            array_slice($scored, 0, $limit)
        );

        return [
            'query' => $query,
            'total' => count($scored),
            'results' => $results,
            'groups' => [],
            'hint' => $results === []
                ? 'No callable function matched. Try fewer or broader keywords, or an empty query for a summary.'
                : 'Call wrapper_moodle_api_describe with the function names to get exact parameter schemas.',
        ];
    }

    /**
     * Full description of one visible entry.
     *
     * @param array $entry Visible entry.
     * @return array
     */
    public static function describe(array $entry): array {
        return [
            'name' => $entry['name'],
            'component' => $entry['component'],
            'domain' => $entry['domain'],
            'type' => self::type_of($entry),
            'readOnly' => self::type_of($entry) === 'read',
            'description' => $entry['description'],
            'requiredCapabilities' => $entry['capabilities'],
            'likelyPermitted' => self::likely($entry),
            'missingCapabilities' => $entry['eligibility']['missingCapabilities'] ?? [],
            'risk' => [
                'level' => $entry['risk']['level'],
                'signals' => $entry['risk']['signals'],
                'destructive' => $entry['risk']['destructive'],
            ],
            'requiredParams' => self::required_params($entry['inputSchema']),
            'inputSchema' => $entry['inputSchema'],
            'outputSchema' => $entry['outputSchema'],
            'exampleArgs' => self::example($entry['inputSchema']) ?? new \stdClass(),
        ];
    }

    /**
     * Build an example arguments skeleton containing required parameters.
     *
     * @param array $schema JSON schema.
     * @param int $depth Recursion depth.
     * @return mixed
     */
    public static function example(array $schema, int $depth = 0): mixed {
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }
        if ($depth > 8) {
            return null;
        }

        $type = ((array)($schema['type'] ?? 'string'))[0];
        if ($type === 'object') {
            $example = [];
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach (($schema['required'] ?? []) as $key) {
                if (isset($properties[$key])) {
                    $example[$key] = self::example($properties[$key], $depth + 1);
                }
            }
            return $example === [] ? new \stdClass() : $example;
        }

        return match ($type) {
            'array' => [self::example(is_array($schema['items'] ?? null) ? $schema['items'] : [], $depth + 1)],
            'integer', 'number' => 0,
            'boolean' => false,
            default => '',
        };
    }

    /**
     * Compact top-level required parameter list, e.g. ["courseid:integer", "options:array"].
     *
     * @param array $schema Input schema.
     * @return string[]
     */
    public static function required_params(array $schema): array {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $params = [];
        foreach ($schema['required'] ?? [] as $key) {
            $params[] = $key . ':' . ((array)($properties[$key]['type'] ?? 'any'))[0];
        }
        return $params;
    }

    /**
     * Whether the user holds every declared capability that could be checked in the restricted context.
     *
     * Declared capabilities are informational: false does not mean the call will fail.
     *
     * @param array $entry Visible entry.
     * @return bool
     */
    public static function likely(array $entry): bool {
        return (bool)($entry['eligibility']['likelyPermitted'] ?? true);
    }

    /**
     * Normalize the read/write type of an entry.
     *
     * @param array $entry Catalog entry.
     * @return string
     */
    public static function type_of(array $entry): string {
        return ($entry['mutability'] ?? 'write') === 'read' ? 'read' : 'write';
    }

    /**
     * Summarize entries by domain and component.
     *
     * @param array $entries Entries.
     * @return array
     */
    private static function summary(array $entries): array {
        $groups = [];
        foreach ($entries as $entry) {
            $domain = (string)$entry['domain'];
            $component = (string)$entry['component'];
            $groups[$domain]['domain'] = $domain;
            $groups[$domain]['count'] = ($groups[$domain]['count'] ?? 0) + 1;
            $groups[$domain]['components'][$component] = ($groups[$domain]['components'][$component] ?? 0) + 1;
        }
        ksort($groups);

        return array_values(array_map(static function (array $group): array {
            ksort($group['components']);
            $group['components'] = array_map(
                static fn(string $component, int $count): array => ['component' => $component, 'count' => $count],
                array_keys($group['components']),
                array_values($group['components'])
            );
            return $group;
        }, $groups));
    }

    /**
     * Split a query into lowercase search tokens (keeping "/" and ":" so capabilities stay whole).
     *
     * @param string $query Query.
     * @return string[]
     */
    private static function tokens(string $query): array {
        $tokens = preg_split('/[^a-z0-9\/:]+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($tokens));
    }

    /**
     * Score an entry: [number of tokens matched, weighted score].
     *
     * @param array $entry Catalog entry.
     * @param string[] $tokens Query tokens.
     * @param string $phrase Lowercased full query.
     * @return int[]
     */
    private static function score(array $entry, array $tokens, string $phrase): array {
        $name = strtolower($entry['name']);
        $component = strtolower($entry['component']);
        $description = strtolower($entry['description']);
        $capabilities = strtolower(implode(' ', $entry['capabilities'] ?? []));
        $params = self::param_names($entry['inputSchema'] ?? []);

        $matched = 0;
        $score = $name === $phrase ? 100 : (str_contains($name, $phrase) ? 20 : 0);
        foreach ($tokens as $token) {
            // Accept simple plurals ("users" matches "user").
            $variants = array_unique([$token, strlen($token) > 3 ? rtrim($token, 's') : $token]);
            $tokenscore = 0;
            foreach ($variants as $variant) {
                $tokenscore = max(
                    $tokenscore,
                    (preg_match('/(^|_)' . preg_quote($variant, '/') . '(_|$)/', $name) === 1 ? 6 : 0)
                    + (str_contains($name, $variant) ? 4 : 0)
                    + (str_contains($component, $variant) ? 2 : 0)
                    + (str_contains($capabilities, $variant) ? 2 : 0)
                    + (isset($params[$variant]) ? 2 : 0)
                    + (str_contains($description, $variant) ? 1 : 0)
                );
            }
            if ($tokenscore > 0) {
                $matched++;
                $score += $tokenscore;
            }
        }

        return [$matched, $score];
    }

    /**
     * Collect lowercase parameter names at any depth of an input schema.
     *
     * @param array $schema Schema.
     * @param int $depth Recursion depth.
     * @return array Name => true.
     */
    private static function param_names(array $schema, int $depth = 0): array {
        if ($depth > 8) {
            return [];
        }

        $names = [];
        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $key => $subschema) {
                $names[strtolower((string)$key)] = true;
                $names += self::param_names(is_array($subschema) ? $subschema : [], $depth + 1);
            }
        }
        if (is_array($schema['items'] ?? null)) {
            $names += self::param_names($schema['items'], $depth + 1);
        }

        return $names;
    }

    /**
     * Project a search hit.
     *
     * @param array $entry Visible catalog entry.
     * @param bool $detailed Include requiredParams and exampleArgs.
     * @return array
     */
    private static function hit(array $entry, bool $detailed): array {
        $description = trim(preg_replace('/\s+/', ' ', strip_tags((string)$entry['description'])));
        if (\core_text::strlen($description) > self::SNIPPET_LENGTH) {
            $description = \core_text::substr($description, 0, self::SNIPPET_LENGTH - 3) . '...';
        }

        $hit = [
            'name' => $entry['name'],
            'component' => $entry['component'],
            'domain' => $entry['domain'],
            'type' => self::type_of($entry),
            'readOnly' => self::type_of($entry) === 'read',
            'description' => $description,
            'risk' => $entry['risk']['level'],
            'likelyPermitted' => self::likely($entry),
            'missingCapabilities' => $entry['eligibility']['missingCapabilities'] ?? [],
        ];
        if ($detailed) {
            $hit['requiredParams'] = self::required_params($entry['inputSchema']);
            $hit['exampleArgs'] = self::example($entry['inputSchema']) ?? new \stdClass();
        }

        return $hit;
    }
}
