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

use context;
use context_course;
use context_system;
use core_badges\badge;
use core_external\external_api;
use stdClass;

/**
 * Wrapper implementations for badge administration parity gaps.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class badge_service {
    /** @var badge_record_builder */
    private badge_record_builder $records;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->records = new badge_record_builder();
    }

    /**
     * Create a site or course badge.
     *
     * @param array $payload Badge payload.
     * @param int|null $courseid Optional course id for a course badge.
     * @return array
     */
    public function create_badge(array $payload, ?int $courseid = null): array {
        global $PAGE;
        require_once($this->libdir() . '/badgeslib.php');

        $context = $this->creation_context($courseid);
        external_api::validate_context($context);
        \require_capability('moodle/badges:createbadge', $context);

        if ($PAGE) {
            $PAGE->set_context($context);
        }

        $data = $this->records->badge_payload($payload, null);
        $badge = $this->records->create_badge_record($data, $courseid);

        return $this->badge_result($badge);
    }

    /**
     * Update a badge's details.
     *
     * @param int $badgeid Badge id.
     * @param array $payload Badge payload.
     * @return array
     */
    public function update_badge(int $badgeid, array $payload): array {
        require_once($this->libdir() . '/badgeslib.php');

        $badge = $this->load_badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:configuredetails', $context);

        // The badge details form is frozen for active or locked badges.
        if ($badge->is_active() || $badge->is_locked()) {
            throw new \moodle_exception('wrapper:badgelocked', 'webservice_mcp');
        }

        $data = $this->records->badge_payload($payload, $badge);
        $this->records->apply_badge_update($badge, $data);

        return $this->badge_result($badge);
    }

    /**
     * Update a badge's message settings.
     *
     * @param int $badgeid Badge id.
     * @param array $payload Message payload.
     * @return array
     */
    public function update_badge_message(int $badgeid, array $payload): array {
        require_once($this->libdir() . '/badgeslib.php');

        $badge = $this->load_badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:configuremessages', $context);

        $data = $this->records->badge_message_payload($payload, $badge);
        $this->records->apply_badge_message_update($badge, $data);

        return $this->badge_result($badge);
    }

    /**
     * Delete badges.
     *
     * @param array $badgeids Badge ids.
     * @param bool $archive Whether awarded badges should be archived where supported.
     * @return array
     */
    public function delete_badges(array $badgeids, bool $archive = true): array {
        require_once($this->libdir() . '/badgeslib.php');

        $badgeids = $this->normalize_ids($badgeids);
        foreach ($badgeids as $badgeid) {
            $badge = $this->load_badge($badgeid);
            $context = $badge->get_context();
            external_api::validate_context($context);
            \require_capability('moodle/badges:deletebadge', $context);
            $badge->delete($archive);
        }

        return [
            'deleted' => true,
            'badgeids' => $badgeids,
        ];
    }

    /**
     * Duplicate a badge.
     *
     * @param int $badgeid Badge id.
     * @return array
     */
    public function duplicate_badge(int $badgeid): array {
        global $PAGE;
        require_once($this->libdir() . '/badgeslib.php');

        $badge = $this->load_badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:createbadge', $context);
        \require_capability('moodle/badges:configuredetails', $context);

        if ($PAGE) {
            $PAGE->set_context($context);
        }

        $newbadgeid = (int)$badge->make_clone();
        return $this->badge_result(new badge($newbadgeid));
    }

    /**
     * Add related badges.
     *
     * @param int $badgeid Badge id.
     * @param array $relatedbadgeids Related badge ids.
     * @return array
     */
    public function add_related_badges(int $badgeid, array $relatedbadgeids): array {
        require_once($this->libdir() . '/badgeslib.php');

        $badge = $this->editable_relation_badge($badgeid);
        $relatedbadgeids = $this->normalize_ids($relatedbadgeids);
        if ($relatedbadgeids === []) {
            throw new \moodle_exception('invalidparameter');
        }

        $this->require_relatable_badges($badge, $relatedbadgeids);
        $badge->add_related_badges($relatedbadgeids);

        return [
            'badgeid' => $badgeid,
            'relatedbadgeids' => $relatedbadgeids,
            'status' => true,
        ];
    }

    /**
     * Delete related badges.
     *
     * @param int $badgeid Badge id.
     * @param array $relatedbadgeids Related badge ids.
     * @return array
     */
    public function delete_related_badges(int $badgeid, array $relatedbadgeids): array {
        require_once($this->libdir() . '/badgeslib.php');

        $badge = $this->editable_relation_badge($badgeid);
        $relatedbadgeids = $this->normalize_ids($relatedbadgeids);
        foreach ($relatedbadgeids as $relatedbadgeid) {
            $badge->delete_related_badge($relatedbadgeid);
        }

        return [
            'badgeid' => $badgeid,
            'relatedbadgeids' => $relatedbadgeids,
            'status' => true,
        ];
    }

    /**
     * Save a badge alignment.
     *
     * @param int $badgeid Badge id.
     * @param array $payload Alignment payload.
     * @param int|null $alignmentid Optional existing alignment id.
     * @return array
     */
    public function save_alignment(int $badgeid, array $payload, ?int $alignmentid = null): array {
        global $DB;
        require_once($this->libdir() . '/badgeslib.php');

        $badge = $this->editable_relation_badge($badgeid);
        if (
            $alignmentid !== null && $alignmentid > 0 &&
                !$DB->record_exists('badge_alignment', ['id' => $alignmentid, 'badgeid' => $badgeid])
        ) {
            throw arguments::invalid('alignmentid does not belong to this badge.');
        }
        $alignment = $this->records->alignment_payload($payload);
        $alignment->badgeid = $badgeid;
        $newalignmentid = (int)$badge->save_alignment($alignment, (int)($alignmentid ?? 0));

        return [
            'badgeid' => $badgeid,
            'alignmentid' => $newalignmentid > 0 ? $newalignmentid : (int)($alignmentid ?? 0),
            'status' => true,
        ];
    }

    /**
     * Delete badge alignments.
     *
     * @param int $badgeid Badge id.
     * @param array $alignmentids Alignment ids.
     * @return array
     */
    public function delete_alignments(int $badgeid, array $alignmentids): array {
        require_once($this->libdir() . '/badgeslib.php');

        $badge = $this->editable_relation_badge($badgeid);
        $alignmentids = $this->normalize_ids($alignmentids);
        foreach ($alignmentids as $alignmentid) {
            $badge->delete_alignment($alignmentid);
        }

        return [
            'badgeid' => $badgeid,
            'alignmentids' => $alignmentids,
            'status' => true,
        ];
    }

    /**
     * Manually award a badge to a user.
     *
     * @param int $badgeid Badge id.
     * @param int $recipientid User id.
     * @param int|null $issuerroleid Optional explicit issuer role.
     * @return array
     */
    public function award_badge(int $badgeid, int $recipientid, ?int $issuerroleid = null): array {
        global $CFG, $USER;
        require_once($this->libdir() . '/badgeslib.php');
        require_once($this->dirroot() . '/badges/lib/awardlib.php');

        $badge = $this->load_badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:awardbadge', $context);

        if (!$badge->is_active()) {
            throw new \moodle_exception('donotaward', 'badges');
        }
        $this->require_valid_recipient($badge, $recipientid);

        $resolvedroleid = $this->resolve_manual_issuer_role($badge, $issuerroleid);
        $awarded = \process_manual_award($recipientid, (int)$USER->id, $resolvedroleid, $badgeid);
        if ($awarded && isset($badge->criteria[BADGE_CRITERIA_TYPE_MANUAL])) {
            \badges_award_handle_manual_criteria_review((object)[
                'crit' => $badge->criteria[BADGE_CRITERIA_TYPE_MANUAL],
                'userid' => $recipientid,
            ]);
        }

        return [
            'badgeid' => $badgeid,
            'recipientid' => $recipientid,
            'issuerroleid' => $resolvedroleid,
            'awarded' => $awarded,
            'issued' => method_exists($badge, 'is_issued') ? (bool)$badge->is_issued($recipientid) : null,
        ];
    }

    /**
     * Revoke a manually awarded badge.
     *
     * @param int $badgeid Badge id.
     * @param int $recipientid User id.
     * @param int|null $issuerroleid Optional explicit issuer role.
     * @return array
     */
    public function revoke_badge(int $badgeid, int $recipientid, ?int $issuerroleid = null): array {
        global $CFG, $USER;
        require_once($this->libdir() . '/badgeslib.php');
        require_once($this->dirroot() . '/badges/lib/awardlib.php');

        $badge = $this->load_badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:revokebadge', $context);

        $resolvedroleid = $this->resolve_manual_issuer_role($badge, $issuerroleid);
        $revoked = \process_manual_revoke($recipientid, (int)$USER->id, $resolvedroleid, $badgeid);

        return [
            'badgeid' => $badgeid,
            'recipientid' => $recipientid,
            'issuerroleid' => $resolvedroleid,
            'revoked' => $revoked,
        ];
    }

    /**
     * Ensure the recipient is a real user who may earn the badge, as the award page's user selector does.
     *
     * @param badge $badge Badge object.
     * @param int $recipientid User id.
     * @return void
     */
    private function require_valid_recipient(badge $badge, int $recipientid): void {
        global $DB;

        $recipient = $DB->get_record('user', ['id' => $recipientid, 'deleted' => 0]);
        if (!$recipient || \isguestuser($recipient) || !empty($recipient->suspended)) {
            throw new \moodle_exception('wrapper:badgerecipientinvalid', 'webservice_mcp', '', $recipientid);
        }

        $context = $badge->get_context();
        $canearn = (int)$context->contextlevel === CONTEXT_COURSE
            ? \is_enrolled($context, $recipient, 'moodle/badges:earnbadge', true)
            : \has_capability('moodle/badges:earnbadge', $context, $recipient);
        if (!$canearn) {
            throw new \moodle_exception('wrapper:badgerecipientinvalid', 'webservice_mcp', '', $recipientid);
        }
    }

    /**
     * Ensure a badge is editable through relation/alignment flows.
     *
     * @param int $badgeid Badge id.
     * @return badge
     */
    private function editable_relation_badge(int $badgeid): badge {
        $badge = $this->load_badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:configuredetails', $context);

        if ($badge->is_active() || $badge->is_locked()) {
            throw new \moodle_exception('invalidparameter');
        }

        return $badge;
    }

    /**
     * Resolve the creation context for a site or course badge.
     *
     * @param int|null $courseid Optional course id.
     * @return context
     */
    private function creation_context(?int $courseid): context {
        $this->require_badges_enabled($courseid !== null);

        return $courseid === null ? context_system::instance() : context_course::instance($courseid, MUST_EXIST);
    }

    /**
     * Ensure related badges exist, are not the badge itself, and for a course badge belong to the same course or
     * are site badges (badges/related_form.php get_badges_option()).
     *
     * @param badge $badge Badge being edited.
     * @param int[] $relatedbadgeids Candidate related badge ids.
     * @return void
     */
    private function require_relatable_badges(badge $badge, array $relatedbadgeids): void {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($relatedbadgeids, SQL_PARAMS_NAMED);
        $sql = "id {$insql} AND id <> :badgeid";
        $params['badgeid'] = $badge->id;
        if ((int)$badge->type === BADGE_TYPE_COURSE) {
            $sql .= ' AND (courseid = :courseid OR type = :sitetype)';
            $params['courseid'] = $badge->courseid;
            $params['sitetype'] = BADGE_TYPE_SITE;
        }
        if ($DB->count_records_select('badge', $sql, $params) !== count($relatedbadgeids)) {
            throw arguments::invalid('Related badges must exist, differ from the badge, and for a course '
                . 'badge be site badges or badges of the same course.');
        }
    }

    /**
     * Load a badge after checking the site badge switches allow working with it.
     *
     * @param int $badgeid Badge id.
     * @return badge
     */
    private function load_badge(int $badgeid): badge {
        require_once($this->libdir() . '/badgeslib.php');

        $badge = new badge($badgeid);
        $this->require_badges_enabled((int)$badge->type === BADGE_TYPE_COURSE);

        return $badge;
    }

    /**
     * Refuse badge operations when badges (or course badges) are disabled, as the core badge pages do.
     *
     * @param bool $coursebadge Whether the badge is a course badge.
     * @return void
     */
    private function require_badges_enabled(bool $coursebadge): void {
        global $CFG;

        if (empty($CFG->enablebadges)) {
            throw new \moodle_exception('badgesdisabled', 'badges');
        }
        if ($coursebadge && empty($CFG->badges_allowcoursebadges)) {
            throw new \moodle_exception('coursebadgesdisabled', 'badges');
        }
    }

    /**
     * Resolve the issuer role for manual award/revoke flows.
     *
     * @param badge $badge Badge object.
     * @param int|null $requestedroleid Optional explicit role.
     * @return int
     */
    private function resolve_manual_issuer_role(badge $badge, ?int $requestedroleid = null): int {
        global $USER;

        if (empty($badge->criteria[BADGE_CRITERIA_TYPE_MANUAL])) {
            throw new \moodle_exception('invalidparameter');
        }

        $acceptedroles = array_values(array_map('intval', array_keys($badge->criteria[BADGE_CRITERIA_TYPE_MANUAL]->params)));
        if ($acceptedroles === []) {
            throw new \moodle_exception('invalidparameter');
        }

        if ($requestedroleid !== null) {
            if (!in_array($requestedroleid, $acceptedroles, true) && !is_siteadmin()) {
                throw new \moodle_exception('invalidparameter');
            }

            if (!is_siteadmin()) {
                $roles = \get_user_roles($badge->get_context(), $USER->id);
                $roleids = array_map(static fn(stdClass $role): int => (int)$role->roleid, $roles);
                if (!in_array($requestedroleid, $roleids, true)) {
                    throw new \moodle_exception('notacceptedrole', 'badges');
                }
            }

            return $requestedroleid;
        }

        if (count($acceptedroles) === 1) {
            $roleid = $acceptedroles[0];
            if (!is_siteadmin()) {
                $users = \get_role_users($roleid, $badge->get_context(), true, 'u.id', 'u.id ASC');
                if (!in_array((int)$USER->id, array_map('intval', array_keys($users)), true)) {
                    throw new \moodle_exception('notacceptedrole', 'badges');
                }
            }
            return $roleid;
        }

        if (is_siteadmin()) {
            return $acceptedroles[0];
        }

        $roles = \get_user_roles($badge->get_context(), $USER->id);
        $roleids = array_map(static fn(stdClass $role): int => (int)$role->roleid, $roles);
        $selection = array_values(array_intersect($acceptedroles, $roleids));
        if ($selection === []) {
            throw new \moodle_exception('notacceptedrole', 'badges');
        }

        return (int)$selection[0];
    }

    /**
     * Return structured badge metadata.
     *
     * @param badge $badge Badge object.
     * @return array
     */
    private function badge_result(badge $badge): array {
        return [
            'badgeid' => (int)$badge->id,
            'name' => (string)$badge->name,
            'type' => (int)$badge->type,
            'courseid' => $badge->courseid !== null ? (int)$badge->courseid : null,
            'status' => (int)$badge->status,
        ];
    }

    /**
     * Normalize an id list to unique positive integers.
     *
     * @param array $ids Raw ids.
     * @return array
     */
    private function normalize_ids(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }

    /**
     * Return Moodle libdir.
     *
     * @return string
     */
    private function libdir(): string {
        global $CFG;
        return $CFG->libdir;
    }

    /**
     * Return Moodle dirroot.
     *
     * @return string
     */
    private function dirroot(): string {
        global $CFG;
        return $CFG->dirroot;
    }
}
