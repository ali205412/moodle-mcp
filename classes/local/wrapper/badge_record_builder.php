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

use core_badges\badge;
use html_writer;
use stdClass;

/**
 * Builds and persists badge records and message settings for the badge wrappers.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class badge_record_builder {
    /**
     * Build a full badge payload from partial input and current badge data, cleaned as badges/classes/form/badge.php
     * cleans and validates it.
     *
     * @param array $payload Raw payload.
     * @param badge|null $existing Existing badge.
     * @return stdClass
     */
    public function badge_payload(array $payload, ?badge $existing): stdClass {
        global $CFG, $SITE;

        $value = static fn(string $key, $default) => is_scalar($payload[$key] ?? null) ? (string)$payload[$key]
            : (string)($existing->{$key} ?? $default);

        $data = new stdClass();
        $data->name = trim(clean_param($value('name', ''), PARAM_TEXT));
        if ($data->name === '') {
            throw arguments::invalid('Badge name must not be empty.');
        }
        $data->version = clean_param($value('version', 'v1'), PARAM_TEXT);
        $data->language = $value('language', current_language());
        if (!array_key_exists($data->language, get_string_manager()->get_list_of_languages())) {
            throw arguments::invalid('language must be a language code such as "en".');
        }
        $data->description = clean_param($value('description', ''), PARAM_NOTAGS);
        $data->imageauthorname = clean_param($value('imageauthorname', ''), PARAM_TEXT);
        $data->imageauthoremail = self::email(clean_param($value('imageauthoremail', ''), PARAM_TEXT), 'imageauthoremail');
        $data->imageauthorurl = self::url($value('imageauthorurl', ''), 'imageauthorurl');
        $data->imagecaption = clean_param($value('imagecaption', ''), PARAM_TEXT);
        $data->issuername = clean_param($value('issuername', format_string($SITE->fullname)), PARAM_NOTAGS);
        $data->issuerurl = self::url($value('issuerurl', $CFG->wwwroot), 'issuerurl');
        $data->issuercontact = self::email($value('issuercontact', ''), 'issuercontact');

        [$expiry, $expiredate, $expireperiod] = $this->badge_expiry_values($payload, $existing);
        if (($expiry === 1 && (int)$expiredate <= time()) || ($expiry === 2 && (int)$expireperiod <= 0)) {
            throw arguments::invalid('expiry 1 needs a future expiredate; expiry 2 needs a positive expireperiod.');
        }
        $data->expiry = $expiry;
        $data->expiredate = $expiredate;
        $data->expireperiod = $expireperiod;

        $tags = $payload['tags'] ?? null;
        if (is_array($tags)) {
            $tags = array_values(array_filter(array_map(
                static fn($tag): string => is_scalar($tag) ? clean_param((string)$tag, PARAM_TAG) : '',
                $tags
            ), 'strlen'));
        } else if ($existing && method_exists($existing, 'get_badge_tags')) {
            $tags = $existing->get_badge_tags();
        } else {
            // Moodle 4.2 has no badge::get_badge_tags().
            $tags = $existing ? \core_tag_tag::get_item_tags_array('core_badges', 'badge', $existing->id) : [];
        }
        $data->tags = $tags;

        return $data;
    }

    /**
     * Build a badge-message payload, cleaned as badges/classes/form/message.php and badge::update_message() do.
     *
     * @param array $payload Raw payload.
     * @param badge $existing Existing badge.
     * @return stdClass
     */
    public function badge_message_payload(array $payload, badge $existing): stdClass {
        $data = new stdClass();
        $data->messagesubject = clean_param((string)($payload['messagesubject'] ?? $existing->messagesubject ?? ''), PARAM_TEXT);
        $data->message_editor = [
            'text' => clean_text((string)($payload['message'] ?? $existing->message ?? ''), FORMAT_HTML),
            'format' => FORMAT_HTML,
        ];
        $data->notification = (int)($payload['notification'] ?? $existing->notification ?? BADGE_MESSAGE_NEVER);
        $data->attachment = array_key_exists('attachment', $payload)
            ? (int)arguments::to_bool($payload['attachment'])
            : (int)($existing->attachment ?? 1);

        return $data;
    }

    /**
     * Build an alignment record, cleaned as badges/alignment_form.php cleans and validates it.
     *
     * @param array $payload Raw payload.
     * @return stdClass
     */
    public function alignment_payload(array $payload): stdClass {
        $text = static fn(string $key): string => is_scalar($payload[$key] ?? null) ? (string)$payload[$key] : '';

        $alignment = (object)[
            'targetname' => trim(clean_param($text('targetname'), PARAM_TEXT)),
            'targeturl' => self::url($text('targeturl'), 'targeturl'),
            'targetdescription' => clean_param($text('targetdescription'), PARAM_NOTAGS),
            'targetframework' => clean_param($text('targetframework'), PARAM_TEXT),
            'targetcode' => clean_param($text('targetcode'), PARAM_TEXT),
        ];
        if ($alignment->targetname === '' || $alignment->targeturl === '') {
            throw arguments::invalid('targetname and an http(s) targeturl are required.');
        }

        return $alignment;
    }

    /**
     * Clean a URL as PARAM_URL and require http(s), as the badge forms do (rejects javascript: and similar).
     *
     * @param string $url Raw URL.
     * @param string $field Field name for the error.
     * @return string Cleaned URL, or '' when empty.
     */
    private static function url(string $url, string $field): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $clean = clean_param($url, PARAM_URL);
        if ($clean === '' || !preg_match('@^https?://.+@i', $clean)) {
            throw arguments::invalid("{$field} must be an http(s) URL.");
        }

        return $clean;
    }

    /**
     * Validate an optional email address.
     *
     * @param string $email Raw email.
     * @param string $field Field name for the error.
     * @return string
     */
    private static function email(string $email, string $field): string {
        $email = trim($email);
        if ($email !== '' && !validate_email($email)) {
            throw arguments::invalid("{$field} must be a valid email address.");
        }

        return $email;
    }

    /**
     * Apply a badge update with runtime fallbacks for older supported branches.
     *
     * @param badge $badge Badge object.
     * @param stdClass $data Badge payload.
     * @return void
     */
    public function apply_badge_update(badge $badge, stdClass $data): void {
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
    public function apply_badge_message_update(badge $badge, stdClass $data): void {
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
    public function create_badge_record(stdClass $data, ?int $courseid = null): badge {
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
     * Return Moodle wwwroot.
     *
     * @return string
     */
    private function wwwroot(): string {
        global $CFG;
        return $CFG->wwwroot;
    }
}
