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

/**
 * completion/complete for prompt and resource-template arguments.
 *
 * Suggestions come only from data the user can already see (own courses, visible
 * activities, participants they may view).
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completions {
    /** Spec maximum values per response. */
    private const MAX = 100;

    /** @var call_context */
    private call_context $ctx;

    /**
     * Constructor.
     *
     * @param call_context $ctx Request context.
     */
    public function __construct(call_context $ctx) {
        $this->ctx = $ctx;
    }

    /**
     * completion/complete.
     *
     * @param array $params Request params: ref, argument{name,value}, context{arguments}.
     * @return array
     */
    public function complete(array $params): array {
        $argument = (string)($params['argument']['name'] ?? '');
        $value = trim((string)($params['argument']['value'] ?? ''));
        $known = is_array($params['context']['arguments'] ?? null) ? $params['context']['arguments'] : [];

        $values = match ($argument) {
            'courseid', 'template_courseid' => $this->courses($value),
            'cmid' => $this->modules($value, $known['courseid'] ?? null),
            'userid' => $this->users($value, $known['courseid'] ?? null),
            default => [],
        };

        $total = count($values);
        return ['completion' => [
            'values' => array_slice($values, 0, self::MAX),
            'total' => $total,
            'hasMore' => $total > self::MAX,
        ]];
    }

    /**
     * Course ids whose id, shortname or fullname match.
     *
     * @param string $value Typed prefix.
     * @return string[]
     */
    private function courses(string $value): array {
        $matches = [];
        foreach (enrol_get_my_courses(['id', 'shortname', 'fullname'], 'fullname ASC') as $course) {
            if (
                $this->accessible_course((int)$course->id)
                    && self::matches($value, [(string)$course->id, $course->shortname, $course->fullname])
            ) {
                $matches[] = (string)$course->id;
            }
        }
        return $matches;
    }

    /**
     * Visible course module ids in a course (or in all of the user's courses).
     *
     * @param string $value Typed prefix.
     * @param mixed $courseid Optional course id from context.
     * @return string[]
     */
    private function modules(string $value, mixed $courseid): array {
        $courseids = ctype_digit((string)$courseid)
            ? [(int)$courseid]
            : array_keys(enrol_get_my_courses(['id'], 'fullname ASC', 20));
        $matches = [];
        foreach ($courseids as $id) {
            // The uservisible flag alone doesn't check course access, so hidden or unenrolled courses would leak names.
            if (!$this->accessible_course((int)$id)) {
                continue;
            }
            foreach (get_fast_modinfo($id, (int)$this->ctx->user->id)->get_cms() as $cm) {
                if ($cm->uservisible && self::matches($value, [(string)$cm->id, $cm->name, $cm->modname])) {
                    $matches[] = (string)$cm->id;
                }
            }
        }
        return $matches;
    }

    /**
     * Participant ids in a course, through core_enrol_get_enrolled_users so Moodle applies
     * viewparticipants, separate groups and the token's restrictions.
     *
     * @param string $value Typed prefix.
     * @param mixed $courseid Course id from context.
     * @return string[]
     */
    private function users(string $value, mixed $courseid): array {
        if (!ctype_digit((string)$courseid) || !$this->accessible_course((int)$courseid)) {
            return [];
        }
        try {
            $users = resources::call('core_enrol_get_enrolled_users', ['courseid' => (int)$courseid, 'options' => [
                ['name' => 'userfields', 'value' => 'id,fullname'],
                ['name' => 'limitnumber', 'value' => '500'],
            ]], $this->ctx);
        } catch (\moodle_exception $e) {
            // No permission to list participants: offer no suggestions, as for any other unknown value.
            return [];
        }
        $matches = [];
        foreach ($users as $user) {
            if (self::matches($value, [(string)$user['id'], (string)($user['fullname'] ?? '')])) {
                $matches[] = (string)$user['id'];
            }
        }
        return $matches;
    }

    /**
     * Whether the course exists, lies inside the token's restricted context and the user can access it.
     *
     * @param int $courseid Course id.
     * @return bool
     */
    private function accessible_course(int $courseid): bool {
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        $restricted = $this->ctx->restrictedcontext;
        if ($restricted && !$context->is_child_of($restricted, true)) {
            return false;
        }
        return can_access_course(get_course($courseid), $this->ctx->user, '', true);
    }

    /**
     * Case-insensitive prefix match on ids and substring match on labels.
     *
     * @param string $value Typed value.
     * @param string[] $candidates Id first, then labels.
     * @return bool
     */
    private static function matches(string $value, array $candidates): bool {
        if ($value === '') {
            return true;
        }
        if (str_starts_with($candidates[0], $value)) {
            return true;
        }
        foreach (array_slice($candidates, 1) as $label) {
            if (\core_text::strpos(\core_text::strtolower((string)$label), \core_text::strtolower($value)) !== false) {
                return true;
            }
        }
        return false;
    }
}
