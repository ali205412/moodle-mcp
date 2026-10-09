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

namespace webservice_mcp\local\discovery;

use context;
use stdClass;

/**
 * Cached per-user visible catalog sets and API search results.
 *
 * This cache only speeds up listing and searching. Execution always goes through Moodle's own capability and
 * context checks (call_external_function / wrapper checks), so a stale entry can at worst show a tool for up to
 * the 300s TTL. Keys include a permission marker so role and capability changes take effect on the next request.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class visibility_cache {
    /** Cache definition name. */
    private const CACHE = 'mcp_visibility';

    /** @var eligibility_resolver */
    private eligibility_resolver $resolver;

    /** @var array Permission markers memoized per user id for this instance. */
    private array $markers = [];

    /**
     * Constructor.
     *
     * @param eligibility_resolver|null $resolver Optional resolver (for tests).
     */
    public function __construct(?eligibility_resolver $resolver = null) {
        $this->resolver = $resolver ?? new eligibility_resolver();
    }

    /**
     * Return visible snapshot entries, keyed by name, with risk and eligibility metadata attached.
     *
     * @param array $snapshot Catalog snapshot (entries + signature).
     * @param int[]|null $serviceids Connector service ids; null means any enabled service.
     * @param context $restrictedcontext Restricted context.
     * @param stdClass|null $user Current user.
     * @param string $connectormode Connector mode label.
     * @return array
     */
    public function visible_entries(
        array $snapshot,
        ?array $serviceids,
        context $restrictedcontext,
        ?stdClass $user,
        string $connectormode = 'default'
    ): array {
        $candidates = self::candidates($snapshot['entries'], $serviceids);
        $key = 'v_' . $this->key($snapshot, $serviceids, $restrictedcontext, $user, $connectormode);
        $cache = self::cache();

        $cached = $cache->get($key);
        if (is_array($cached)) {
            $visible = [];
            foreach ($cached as $name => $meta) {
                if (isset($candidates[$name])) {
                    $visible[$name] = $candidates[$name] + $meta;
                }
            }
            return $visible;
        }

        $visible = $this->resolver->filter_visible($candidates, $restrictedcontext, $user, [
            'connector_mode' => $connectormode,
            'snapshot_entries' => $candidates,
        ]);
        $cache->set($key, array_map(
            static fn(array $entry): array => ['risk' => $entry['risk'], 'eligibility' => $entry['eligibility']],
            $visible
        ));

        return $visible;
    }

    /**
     * Read a cached value derived from a visibility set (e.g. search results).
     *
     * @param string $visibilitykey Key from key().
     * @param array $parts Additional key parts.
     * @return mixed|false
     */
    public function get_derived(string $visibilitykey, array $parts): mixed {
        return self::cache()->get(self::derived_key($visibilitykey, $parts));
    }

    /**
     * Store a value derived from a visibility set.
     *
     * @param string $visibilitykey Key from key().
     * @param array $parts Additional key parts.
     * @param mixed $value Value.
     * @return void
     */
    public function set_derived(string $visibilitykey, array $parts, mixed $value): void {
        self::cache()->set(self::derived_key($visibilitykey, $parts), $value);
    }

    /**
     * Build the visibility key.
     *
     * Composition: user id, restricted context id, sorted service ids (or "any"), catalog signature, connector
     * mode, and the user's permission marker.
     *
     * @param array $snapshot Catalog snapshot.
     * @param int[]|null $serviceids Service ids.
     * @param context $restrictedcontext Restricted context.
     * @param stdClass|null $user Current user.
     * @param string $connectormode Connector mode.
     * @return string
     */
    public function key(
        array $snapshot,
        ?array $serviceids,
        context $restrictedcontext,
        ?stdClass $user,
        string $connectormode = 'default'
    ): string {
        $userid = (int)($user->id ?? 0);
        if ($serviceids !== null) {
            $serviceids = array_values(array_unique(array_map('intval', $serviceids)));
            sort($serviceids);
        }

        return sha1(json_encode([
            $userid,
            (int)$restrictedcontext->id,
            $serviceids ?? 'any',
            (string)($snapshot['signature'] ?? ''),
            $connectormode,
            $this->permission_marker($userid),
        ]));
    }

    /**
     * Purge every cached visibility set and search result.
     *
     * @return void
     */
    public static function purge(): void {
        self::cache()->purge();
    }

    /**
     * Fingerprint of everything that can change the user's capabilities.
     *
     * Covers accesslib's own invalidation flags (dirty contexts after capability/override changes, dirty users after
     * role (un)assignment), the user's role assignments, all role definitions/overrides, and site admins. Changes
     * within the same second to an already-counted row are caught by the TTL backstop.
     *
     * @param int $userid User id.
     * @return string
     */
    public function permission_marker(int $userid): string {
        global $CFG, $DB;

        if (isset($this->markers[$userid])) {
            return $this->markers[$userid];
        }

        $assignments = $DB->get_record_sql(
            'SELECT COUNT(1) AS total, MAX(id) AS maxid, MAX(timemodified) AS changed
               FROM {role_assignments} WHERE userid = ?',
            [$userid]
        );
        $definitions = $DB->get_record_sql(
            'SELECT COUNT(1) AS total, MAX(id) AS maxid, MAX(timemodified) AS changed FROM {role_capabilities}'
        );

        return $this->markers[$userid] = sha1(json_encode([
            (int)$DB->get_field_sql(
                'SELECT MAX(timemodified) FROM {cache_flags} WHERE flagtype = ?',
                ['accesslib/dirtycontexts']
            ),
            (int)$DB->get_field('cache_flags', 'timemodified', ['flagtype' => 'accesslib/dirtyusers', 'name' => (string)$userid]),
            (array)$assignments,
            (array)$definitions,
            (string)($CFG->siteadmins ?? ''),
            (int)($CFG->defaultuserroleid ?? 0),
        ]));
    }

    /**
     * Snapshot entries enabled in the given services (or in any enabled service).
     *
     * @param array $entries Snapshot entries keyed by name.
     * @param int[]|null $serviceids Service ids.
     * @return array
     */
    public static function candidates(array $entries, ?array $serviceids): array {
        if ($serviceids === null) {
            return array_filter($entries, static fn(array $entry): bool => !empty($entry['enabledserviceids']));
        }

        $index = array_fill_keys(array_map('intval', $serviceids), true);
        return array_filter($entries, static function (array $entry) use ($index): bool {
            foreach ($entry['enabledserviceids'] ?? [] as $serviceid) {
                if (isset($index[$serviceid])) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Build a derived key.
     *
     * @param string $visibilitykey Visibility key.
     * @param array $parts Extra parts.
     * @return string
     */
    private static function derived_key(string $visibilitykey, array $parts): string {
        return 'd_' . sha1($visibilitykey . json_encode($parts));
    }

    /**
     * Return the cache instance.
     *
     * @return \cache_application
     */
    private static function cache(): \cache_application {
        return \cache::make('webservice_mcp', self::CACHE);
    }
}
