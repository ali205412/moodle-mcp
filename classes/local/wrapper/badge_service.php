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
use html_writer;
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

        $data = $this->badge_payload($payload, null);
        $badge = $this->create_badge_record($data, $courseid);

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

        $badge = new badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:configuredetails', $context);

        $data = $this->badge_payload($payload, $badge);
        $this->apply_badge_update($badge, $data);

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

        $badge = new badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:configuremessages', $context);

        $data = $this->badge_message_payload($payload, $badge);
        $this->apply_badge_message_update($badge, $data);

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
            $badge = new badge($badgeid);
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

        $badge = new badge($badgeid);
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
        require_once($this->libdir() . '/badgeslib.php');

        $badge = $this->editable_relation_badge($badgeid);
        $alignment = (object)[
            'badgeid' => $badgeid,
            'targetname' => (string)($payload['targetname'] ?? ''),
            'targeturl' => (string)($payload['targeturl'] ?? ''),
            'targetdescription' => (string)($payload['targetdescription'] ?? ''),
            'targetframework' => (string)($payload['targetframework'] ?? ''),
            'targetcode' => (string)($payload['targetcode'] ?? ''),
        ];
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

        $badge = new badge($badgeid);
        $context = $badge->get_context();
        external_api::validate_context($context);
        \require_capability('moodle/badges:awardbadge', $context);

        if (!$badge->is_active()) {
            throw new \moodle_exception('donotaward', 'badges');
        }

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

        $badge = new badge($badgeid);
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
     * Ensure a badge is editable through relation/alignment flows.
     *
     * @param int $badgeid Badge id.
     * @return badge
     */
    private function editable_relation_badge(int $badgeid): badge {
        $badge = new badge($badgeid);
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
        global $CFG;

        if (empty($CFG->enablebadges)) {
            throw new \moodle_exception('badgesdisabled', 'badges');
        }

        if ($courseid === null) {
            return context_system::instance();
        }

        if (empty($CFG->badges_allowcoursebadges)) {
            throw new \moodle_exception('coursebadgesdisabled', 'badges');
        }

        return context_course::instance($courseid, MUST_EXIST);
    }

    /**
     * Build a full badge payload from partial input and current badge data.
     *
     * @param array $payload Raw payload.
     * @param badge|null $existing Existing badge.
     * @return stdClass
     */
    private function badge_payload(array $payload, ?badge $existing): stdClass {
        global $CFG, $SITE;

        $data = new stdClass();
        $data->name = (string)($payload['name'] ?? $existing?->name ?? '');
        $data->version = (string)($payload['version'] ?? $existing?->version ?? 'v1');
        $data->language = (string)($payload['language'] ?? $existing?->language ?? current_language());
        $data->description = (string)($payload['description'] ?? $existing?->description ?? '');
        $data->imageauthorname = (string)($payload['imageauthorname'] ?? $existing?->imageauthorname ?? '');
        $data->imageauthoremail = (string)($payload['imageauthoremail'] ?? $existing?->imageauthoremail ?? '');
        $data->imageauthorurl = (string)($payload['imageauthorurl'] ?? $existing?->imageauthorurl ?? '');
        $data->imagecaption = (string)($payload['imagecaption'] ?? $existing?->imagecaption ?? '');
        $data->issuername = (string)($payload['issuername'] ?? $existing?->issuername ?? format_string($SITE->fullname));
        $data->issuerurl = (string)($payload['issuerurl'] ?? $existing?->issuerurl ?? $CFG->wwwroot);
        $data->issuercontact = (string)($payload['issuercontact'] ?? $existing?->issuercontact ?? '');

        [$expiry, $expiredate, $expireperiod] = $this->badge_expiry_values($payload, $existing);
        $data->expiry = $expiry;
        $data->expiredate = $expiredate;
        $data->expireperiod = $expireperiod;
        $data->tags = $payload['tags'] ?? ($existing ? $existing->get_badge_tags() : []);

        return $data;
    }

    /**
     * Build a badge-message payload from partial input and current badge data.
     *
     * @param array $payload Raw payload.
     * @param badge $existing Existing badge.
     * @return stdClass
     */
    private function badge_message_payload(array $payload, badge $existing): stdClass {
        $data = new stdClass();
        $data->messagesubject = (string)($payload['messagesubject'] ?? $existing->messagesubject ?? '');
        $data->message_editor = [
            'text' => (string)($payload['message'] ?? $existing->message ?? ''),
            'format' => (int)($payload['messageformat'] ?? FORMAT_HTML),
        ];
        $data->notification = (int)($payload['notification'] ?? $existing->notification ?? BADGE_MESSAGE_NEVER);
        $data->attachment = !empty($payload['attachment']) ? 1 : (int)($existing->attachment ?? 1);

        return $data;
    }

    /**
     * Apply a badge update with runtime fallbacks for older supported branches.
     *
     * @param badge $badge Badge object.
     * @param stdClass $data Badge payload.
     * @return void
     */
    private function apply_badge_update(badge $badge, stdClass $data): void {
        global $USER;

        if (method_exists($badge, 'update')) {
            $badge->update($data);
            return;
        }

        $badge->usermodified = $USER->id;
        $badge->name = trim($data->name);
        $badge->version = trim($data->version);
        $badge->language = $data->language;
        $badge->description = $data->description;
        $badge->imageauthorname = $data->imageauthorname;
        $badge->imageauthoremail = $data->imageauthoremail;
        $badge->imageauthorurl = $data->imageauthorurl;
        $badge->imagecaption = $data->imagecaption;
        $badge->issuername = $data->issuername;
        $badge->issuerurl = $data->issuerurl;
        $badge->issuercontact = $data->issuercontact;
        $badge->expiredate = $data->expiry == 1 ? $data->expiredate : null;
        $badge->expireperiod = $data->expiry == 2 ? $data->expireperiod : null;
        $badge->save();

        \core_tag_tag::set_item_tags('core_badges', 'badge', $badge->id, $badge->get_context(), $data->tags);
    }

    /**
     * Apply a badge-message update with runtime fallbacks for older supported branches.
     *
     * @param badge $badge Badge object.
     * @param stdClass $data Message payload.
     * @return void
     */
    private function apply_badge_message_update(badge $badge, stdClass $data): void {
        global $USER;

        if (method_exists($badge, 'update_message')) {
            $badge->update_message($data);
            return;
        }

        if ($data->notification != $badge->notification) {
            if ($data->notification > BADGE_MESSAGE_ALWAYS) {
                $badge->nextcron = \badges_calculate_message_schedule($data->notification);
            } else {
                $badge->nextcron = null;
            }
        }

        $badge->usermodified = $USER->id;
        $badge->messagesubject = $data->messagesubject;
        $badge->message = clean_text($data->message_editor['text'], FORMAT_HTML);
        $badge->notification = $data->notification;
        $badge->attachment = $data->attachment;
        $badge->save();
    }

    /**
     * Resolve the effective expiry tuple for create/update flows.
     *
     * @param array $payload Raw payload.
     * @param badge|null $existing Existing badge.
     * @return array
     */
    private function badge_expiry_values(array $payload, ?badge $existing): array {
        $expiry = isset($payload['expiry']) ? (int)$payload['expiry'] : null;
        $expiredate = isset($payload['expiredate']) ? (int)$payload['expiredate'] : null;
        $expireperiod = isset($payload['expireperiod']) ? (int)$payload['expireperiod'] : null;

        if ($expiry === null && $existing) {
            if (!empty($existing->expiredate)) {
                $expiry = 1;
                $expiredate = (int)$existing->expiredate;
            } else if (!empty($existing->expireperiod)) {
                $expiry = 2;
                $expireperiod = (int)$existing->expireperiod;
            } else {
                $expiry = 0;
            }
        }

        $expiry ??= 0;
        return [$expiry, $expiredate, $expireperiod];
    }

    /**
     * Create a badge using the most compatible path for the current Moodle branch.
     *
     * @param stdClass $data Normalized badge payload.
     * @param int|null $courseid Optional course id for course badges.
     * @return badge
     */
    private function create_badge_record(stdClass $data, ?int $courseid = null): badge {
        global $DB, $USER;

        if (method_exists(badge::class, 'create_badge')) {
            return badge::create_badge($data, $courseid);
        }

        $now = time();
        $record = (object)[
            'courseid' => $courseid,
            'type' => $courseid ? BADGE_TYPE_COURSE : BADGE_TYPE_SITE,
            'name' => trim($data->name),
            'description' => $data->description,
            'timecreated' => $now,
            'timemodified' => $now,
            'usercreated' => $USER->id,
            'usermodified' => $USER->id,
            'issuername' => $data->issuername,
            'issuerurl' => $data->issuerurl,
            'issuercontact' => $data->issuercontact,
            'expiredate' => $data->expiry == 1 ? $data->expiredate : null,
            'expireperiod' => $data->expiry == 2 ? $data->expireperiod : null,
            'messagesubject' => get_string('messagesubject', 'badges'),
            'message' => get_string('messagebody', 'badges', html_writer::link(
                $this->wwwroot() . '/badges/mybadges.php',
                get_string('managebadges', 'badges')
            )),
            'attachment' => 1,
            'notification' => BADGE_MESSAGE_NEVER,
            'status' => BADGE_STATUS_INACTIVE,
            'version' => $data->version,
            'language' => $data->language,
            'imageauthorname' => $data->imageauthorname,
            'imageauthoremail' => $data->imageauthoremail,
            'imageauthorurl' => $data->imageauthorurl,
            'imagecaption' => $data->imagecaption,
        ];

        $record->id = $DB->insert_record('badge', $record, true);
        $badge = new badge($record->id);

        $event = \core\event\badge_created::create([
            'objectid' => $badge->id,
            'context' => $badge->get_context(),
        ]);
        $event->trigger();

        \core_tag_tag::set_item_tags('core_badges', 'badge', $badge->id, $badge->get_context(), $data->tags);

        return $badge;
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

    /**
     * Return Moodle wwwroot.
     *
     * @return string
     */
    private function wwwroot(): string {
        global $CFG;
        return $CFG->wwwroot;
    }
}
