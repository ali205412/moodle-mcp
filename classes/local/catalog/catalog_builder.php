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

namespace webservice_mcp\local\catalog;

use core_external\external_api;

/**
 * Harvest and cache a site-wide catalog of installed external functions.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalog_builder {
    /** Cache definition for the site-wide snapshot. */
    private const CACHE = 'mcp_catalog_snapshot';

    /** Fixed cache key for the single-site snapshot. */
    private const CACHE_KEY = 'sitewide';

    /** @var wrapper_registry */
    private wrapper_registry $wrapperregistry;

    /**
     * Constructor.
     *
     * @param wrapper_registry|null $wrapperregistry Optional wrapper registry override.
     */
    public function __construct(?wrapper_registry $wrapperregistry = null) {
        $this->wrapperregistry = $wrapperregistry ?? new wrapper_registry();
    }

    /**
     * Return the cached site-wide catalog snapshot.
     *
     * @param bool $force Force a rebuild instead of using cache.
     * @return array
     */
    public function get_snapshot(bool $force = false): array {
        $signature = $this->surface_signature();
        if (!$force) {
            $cached = $this->cache()->get(self::CACHE_KEY);
            if (is_array($cached) && ($cached['signature'] ?? '') === $signature) {
                return $cached;
            }
        }

        $snapshot = $this->build_snapshot($signature);
        $this->cache()->set(self::CACHE_KEY, $snapshot);

        return $snapshot;
    }

    /**
     * Invalidate the cached snapshot.
     *
     * @return void
     */
    public function invalidate(): void {
        $this->cache()->delete(self::CACHE_KEY);
    }

    /**
     * Build a fresh site-wide snapshot.
     *
     * @param string $signature Current surface signature.
     * @return array
     */
    protected function build_snapshot(string $signature): array {
        global $DB;

        $functions = $DB->get_records('external_functions', null, 'name ASC');
        $services = $DB->get_records('external_services', null, 'id ASC');
        $links = $DB->get_records('external_services_functions', null, 'externalserviceid ASC, functionname ASC');

        $serviceindex = [];
        foreach ($links as $link) {
            $serviceindex[$link->functionname][] = (int)$link->externalserviceid;
        }

        $entries = [];
        $failures = [];

        foreach ($functions as $record) {
            try {
                $info = $this->external_function_info($record);
            } catch (\Throwable $exception) {
                $failures[] = [
                    'name' => $record->name,
                    'message' => $exception->getMessage(),
                ];
                continue;
            }

            if (!$info || !empty($info->deprecated)) {
                continue;
            }

            $entries[$info->name] = $this->normalize_entry(
                $info,
                $serviceindex[$info->name] ?? [],
                $services
            );
        }

        ksort($entries);

        return [
            'signature' => $signature,
            'generated' => time(),
            'entries' => $entries,
            'coverage' => $this->build_coverage($entries, $this->wrapperregistry->all(), $this->component_list()),
            'failures' => $failures,
        ];
    }

    /**
     * Resolve full function info from Moodle.
     *
     * @param \stdClass $record External function record.
     * @return \stdClass|bool
     */
    protected function external_function_info(\stdClass $record): \stdClass|bool {
        return external_api::external_function_info($record, IGNORE_MISSING);
    }

    /**
     * Return the installed component list.
     *
     * @return array
     */
    protected function component_list(): array {
        if (class_exists('\core\component')) {
            return \core\component::get_component_list();
        }

        return \core_component::get_component_list();
    }

    /**
     * Normalize one harvested external function entry.
     *
     * @param \stdClass $info Resolved external function info.
     * @param array $serviceids Linked service ids.
     * @param array $services All services keyed by id.
     * @return array
     */
    private function normalize_entry(\stdClass $info, array $serviceids, array $services): array {
        $linkedservices = [];
        $enabledserviceids = [];
        $disabledserviceids = [];

        foreach ($serviceids as $serviceid) {
            if (empty($services[$serviceid])) {
                continue;
            }

            $service = $services[$serviceid];
            $linkedservices[] = [
                'id' => (int)$service->id,
                'name' => (string)$service->name,
                'shortname' => (string)($service->shortname ?? ''),
                'enabled' => (bool)$service->enabled,
                'component' => (string)($service->component ?? ''),
                'restrictedusers' => (bool)$service->restrictedusers,
            ];

            if (!empty($service->enabled)) {
                $enabledserviceids[] = (int)$service->id;
            } else {
                $disabledserviceids[] = (int)$service->id;
            }
        }

        $mutability = $this->mutability_for($info);

        return [
            'name' => $info->name,
            'description' => (string)($info->description ?? ''),
            'component' => (string)$info->component,
            'domain' => $this->domain_for_component((string)$info->component),
            'mutability' => $mutability,
            'capabilities' => $this->capabilities((string)($info->capabilities ?? '')),
            'annotations' => $this->annotations_for(
                $mutability,
                (bool)($info->readonlysession ?? false),
                $this->destructive_for($info)
            ),
            'provenance' => [
                'source' => 'harvested',
                'classname' => (string)$info->classname,
                'methodname' => (string)$info->methodname,
                'classpath' => (string)($info->classpath ?? ''),
            ],
            'transport' => [
                'allowedfromajax' => (bool)($info->allowed_from_ajax ?? false),
                'loginrequired' => (bool)($info->loginrequired ?? true),
                'readonlysession' => (bool)($info->readonlysession ?? false),
            ],
            'services' => $linkedservices,
            'enabledserviceids' => $enabledserviceids,
            'disabledserviceids' => $disabledserviceids,
            'inputSchema' => schema_builder::build($info->parameters_desc ?? null),
            'outputSchema' => schema_builder::build($info->returns_desc ?? null),
        ];
    }

    /**
     * Build a domain-level coverage summary.
     *
     * @param array $entries Harvested entries.
     * @param array $wrappers Wrapper descriptors.
     * @param array $componentlist Installed component list.
     * @return array
     */
    private function build_coverage(array $entries, array $wrappers, array $componentlist): array {
        $coverage = [];
        $entrycomponents = [];
        $wrappedcomponents = [];

        foreach ($entries as $entry) {
            $domain = $entry['domain'];
            $component = $entry['component'];
            if (!isset($coverage[$domain])) {
                $coverage[$domain] = $this->empty_coverage_bucket($domain);
            }

            $entrycomponents[$domain][$component] = true;
            if (!empty($entry['enabledserviceids'])) {
                $coverage[$domain]['harvested']++;
            } else {
                $coverage[$domain]['disabled']++;
            }
        }

        foreach ($wrappers as $wrapper) {
            $component = (string)($wrapper['component'] ?? 'local_wrapper');
            $domain = (string)($wrapper['domain'] ?? $this->domain_for_component($component));
            if (!isset($coverage[$domain])) {
                $coverage[$domain] = $this->empty_coverage_bucket($domain);
            }

            $coverage[$domain]['wrapped']++;
            $wrappedcomponents[$domain][$component] = true;
        }

        foreach ($this->flatten_component_list($componentlist) as $component => $path) {
            $domain = $this->domain_for_component($component);
            if (!isset($coverage[$domain])) {
                $coverage[$domain] = $this->empty_coverage_bucket($domain);
            }

            if (empty($entrycomponents[$domain][$component]) && empty($wrappedcomponents[$domain][$component])) {
                $coverage[$domain]['unsupported']++;
            }
        }

        foreach ($coverage as $domain => &$bucket) {
            $bucket['status'] = $this->status_for_bucket($bucket);
        }
        unset($bucket);

        ksort($coverage);
        return $coverage;
    }

    /**
     * Return an empty coverage bucket.
     *
     * @param string $domain Domain id.
     * @return array
     */
    private function empty_coverage_bucket(string $domain): array {
        return [
            'domain' => $domain,
            'label' => $this->domain_label($domain),
            'harvested' => 0,
            'wrapped' => 0,
            'disabled' => 0,
            'unsupported' => 0,
            'status' => 'unsupported',
        ];
    }

    /**
     * Determine the summary status for a bucket.
     *
     * @param array $bucket Coverage bucket.
     * @return string
     */
    private function status_for_bucket(array $bucket): string {
        if (!empty($bucket['harvested'])) {
            return 'harvested';
        }

        if (!empty($bucket['wrapped'])) {
            return 'wrapped';
        }

        if (!empty($bucket['disabled'])) {
            return 'disabled';
        }

        return 'unsupported';
    }

    /**
     * Return the stable cache instance.
     *
     * @return \cache_application
     */
    private function cache(): \cache_application {
        return \cache::make('webservice_mcp', self::CACHE);
    }

    /**
     * Compute a deterministic API surface signature.
     *
     * @return string
     */
    private function surface_signature(): string {
        global $CFG, $DB;

        $signaturedata = [
            'siteversion' => (int)($CFG->version ?? 0),
            'externalfunctions' => $DB->count_records('external_functions'),
            'externalservices' => $DB->count_records('external_services'),
            'externalservicefunctions' => $DB->count_records('external_services_functions'),
            'externalfunctionsmaxid' => (int)($DB->get_field_sql('SELECT MAX(id) FROM {external_functions}') ?? 0),
            'externalservicesmaxid' => (int)($DB->get_field_sql('SELECT MAX(id) FROM {external_services}') ?? 0),
            'externalservicefunctionsmaxid' => (int)($DB->get_field_sql('SELECT MAX(id) FROM {external_services_functions}') ?? 0),
            'installedcomponents' => count($this->flatten_component_list($this->component_list())),
            'wrappersignature' => sha1(json_encode($this->wrapperregistry->all(), JSON_UNESCAPED_SLASHES)),
        ];

        return sha1(json_encode($signaturedata, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Flatten core component lists into component => path pairs.
     *
     * @param array $componentlist Installed component list.
     * @return array
     */
    private function flatten_component_list(array $componentlist): array {
        $flattened = [];
        foreach ($componentlist as $components) {
            foreach ($components as $component => $path) {
                $flattened[$component] = $path;
            }
        }

        return $flattened;
    }

    /**
     * Normalize mutability from function info.
     *
     * @param \stdClass $info Function info.
     * @return string
     */
    private function mutability_for(\stdClass $info): string {
        if (!empty($info->type)) {
            return (string)$info->type;
        }

        $method = strtolower((string)$info->methodname);
        if (str_starts_with($method, 'get') || str_starts_with($method, 'list') || str_starts_with($method, 'view')) {
            return 'read';
        }

        return 'write';
    }

    /**
     * Convert mutability/session hints into MCP annotations.
     *
     * @param string $mutability Mutability hint.
     * @param bool $readonlysession Whether the function advertises readonlysession.
     * @param bool $destructive Whether the function name suggests destructive behavior.
     * @return array
     */
    private function annotations_for(string $mutability, bool $readonlysession, bool $destructive): array {
        $readonly = $mutability === 'read' || $readonlysession;

        return [
            'readOnlyHint' => $readonly,
            'destructiveHint' => $destructive,
            'idempotentHint' => $readonly,
            'openWorldHint' => true,
        ];
    }

    /**
     * Determine whether a function is likely destructive.
     *
     * @param \stdClass $info Function info.
     * @return bool
     */
    private function destructive_for(\stdClass $info): bool {
        $hint = strtolower($info->name . ' ' . $info->methodname);
        return preg_match('/delete|remove|reveal|disable|suspend|revert|purge|reset/', $hint) === 1;
    }

    /**
     * Split capability strings into a normalized list.
     *
     * @param string $capabilities Raw capability string.
     * @return array
     */
    private function capabilities(string $capabilities): array {
        if ($capabilities === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $capabilities));
        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * Map a component to a catalog domain.
     *
     * @param string $component Frankenstyle component name.
     * @return string
     */
    private function domain_for_component(string $component): string {
        if ($component === 'moodle' || $component === 'core' || str_starts_with($component, 'core_')) {
            return 'core';
        }

        $prefix = strtok($component, '_') ?: $component;

        return match ($prefix) {
            'mod', 'assignsubmission', 'assignfeedback', 'availability' => 'activity',
            'tool' => 'admin',
            'auth' => 'authentication',
            'enrol' => 'enrolment',
            'grade', 'gradeexport', 'gradeimport', 'gradereport', 'gradingform' => 'grading',
            'qbank', 'qtype', 'question' => 'question',
            'report' => 'reporting',
            'message' => 'communication',
            'payment' => 'commerce',
            'local' => 'local',
            default => $prefix,
        };
    }

    /**
     * Convert a domain id into a human label.
     *
     * @param string $domain Domain id.
     * @return string
     */
    private function domain_label(string $domain): string {
        return match ($domain) {
            'core' => 'Core',
            'activity' => 'Activities',
            'admin' => 'Administration',
            'authentication' => 'Authentication',
            'enrolment' => 'Enrolment',
            'grading' => 'Grading',
            'question' => 'Question Bank',
            'reporting' => 'Reporting',
            'communication' => 'Communication',
            'commerce' => 'Commerce',
            'local' => 'Local',
            default => ucfirst(str_replace('_', ' ', $domain)),
        };
    }
}
