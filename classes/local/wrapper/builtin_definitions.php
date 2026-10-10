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
 * Built-in connector wrapper tool definitions.
 *
 * Output schemas describe the array each wrapper returns (the transport nests it under "result").
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class builtin_definitions {
    /** Annotations for wrappers that only read state. */
    public const READ = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true];

    /** Annotations for wrappers that create or update state. */
    public const WRITE = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];

    /** Annotations for wrappers that update state to an absolute target, so repeating the call is harmless. */
    public const IDEMPOTENT_WRITE = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];

    /** Annotations for wrappers that delete or revoke. */
    public const DESTRUCTIVE = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false];

    /**
     * Return all built-in definitions.
     *
     * @return definition[]
     */
    public static function all(): array {
        return array_merge(
            self::course_definitions(),
            assessment_definitions::question_definitions(),
            assessment_definitions::gradebook_definitions(),
            self::badge_definitions(),
            self::module_and_memory_definitions(),
            gateway_definitions::all(),
            ui_parity_tools::definitions(),
        );
    }

    /**
     * Course structure wrappers.
     *
     * @return definition[]
     */
    private static function course_definitions(): array {
        $state = self::obj([
            'status' => ['type' => 'boolean'],
            'action' => ['type' => 'string'],
            'statejson' => ['type' => 'string', 'description' => 'JSON-encoded course-format state updates.'],
        ]);
        $courseid = self::id('Course id.');
        $sectionids = self::ids('Course section ids (course_sections.id, not section numbers).');
        $cmids = self::ids('Course module ids (cmid).');

        return [
            self::def(
                'wrapper_course_add_section_after',
                'Add course section',
                'Add a new empty section to a course, optionally right after an existing section.',
                ['moodle/course:update'],
                self::obj(['courseid' => $courseid, 'targetsectionid' => self::id('Insert after this section id.')], ['courseid']),
                $state,
                self::WRITE
            ),
            self::def(
                'wrapper_course_set_section_visibility',
                'Show or hide sections',
                'Show or hide one or more course sections.',
                ['moodle/course:update', 'moodle/course:sectionvisibility'],
                self::obj(
                    ['courseid' => $courseid, 'sectionids' => $sectionids, 'visible' => ['type' => 'boolean']],
                    ['courseid', 'sectionids', 'visible']
                ),
                $state,
                self::IDEMPOTENT_WRITE
            ),
            self::def(
                'wrapper_course_delete_sections',
                'Delete sections',
                'Delete one or more course sections including the activities inside them.',
                ['moodle/course:update', 'moodle/course:movesections'],
                self::obj(['courseid' => $courseid, 'sectionids' => $sectionids], ['courseid', 'sectionids']),
                $state,
                self::DESTRUCTIVE
            ),
            self::def(
                'wrapper_course_create_missing_sections',
                'Ensure sections exist',
                'Ensure the given section numbers (0 = general section) exist in a course, creating any that are missing.',
                ['moodle/course:update', 'moodle/course:manageactivities'],
                self::obj(['courseid' => $courseid, 'sectionnums' => self::ids('Section numbers.')], ['courseid', 'sectionnums']),
                self::obj([
                    'status' => ['type' => 'boolean'],
                    'created' => ['type' => 'boolean'],
                    'sections' => ['type' => 'array', 'items' => self::obj([
                        'id' => ['type' => 'integer'],
                        'section' => ['type' => 'integer'],
                        'sectionnum' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                    ])],
                ]),
                self::IDEMPOTENT_WRITE
            ),
            self::def(
                'wrapper_course_move_module',
                'Move activities',
                'Move course modules into a target section, or before a target module.',
                ['moodle/course:manageactivities'],
                self::obj(['courseid' => $courseid, 'cmids' => $cmids, 'targetsectionid' => self::id('Target section id.'),
                    'targetcmid' => self::id('Place before this cmid.')], ['courseid', 'cmids']),
                $state,
                self::WRITE
            ),
            self::def(
                'wrapper_course_move_section_after',
                'Move sections',
                'Move one or more course sections after a target section.',
                ['moodle/course:movesections'],
                self::obj(
                    ['courseid' => $courseid, 'sectionids' => $sectionids,
                    'targetsectionid' => self::id('Target section id.')],
                    ['courseid', 'sectionids', 'targetsectionid']
                ),
                $state,
                self::WRITE
            ),
            self::def(
                'wrapper_course_set_module_visibility',
                'Show, hide or stealth activities',
                'Show, hide, or stealth (available but not shown on the course page) one or more course modules.',
                ['moodle/course:activityvisibility'],
                self::obj(
                    ['courseid' => $courseid, 'cmids' => $cmids,
                    'visibility' => ['type' => 'string', 'enum' => ['show', 'hide', 'stealth']]],
                    ['courseid', 'cmids', 'visibility']
                ),
                $state,
                self::IDEMPOTENT_WRITE
            ),
            self::def(
                'wrapper_course_duplicate_modules',
                'Duplicate activities',
                'Duplicate course modules (backup/restore based), optionally placing copies in a section or before a module.',
                ['moodle/backup:backuptargetimport', 'moodle/restore:restoretargetimport'],
                self::obj(['courseid' => $courseid, 'cmids' => $cmids, 'targetsectionid' => self::id('Target section id.'),
                    'targetcmid' => self::id('Place before this cmid.')], ['courseid', 'cmids']),
                $state,
                self::WRITE
            ),
            self::def(
                'wrapper_course_delete_modules',
                'Delete activities',
                'Delete one or more course modules and their data.',
                ['moodle/course:manageactivities'],
                self::obj(['courseid' => $courseid, 'cmids' => $cmids], ['courseid', 'cmids']),
                $state,
                self::DESTRUCTIVE
            ),
        ];
    }

    /**
     * Badge wrappers.
     *
     * @return definition[]
     */
    private static function badge_definitions(): array {
        $badge = self::obj([
            'badgeid' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'type' => ['type' => 'integer'],
            'courseid' => ['type' => ['integer', 'null']],
            'status' => ['type' => 'integer'],
        ]);
        $badgeid = self::id('Badge id.');
        $relation = self::obj([
            'badgeid' => ['type' => 'integer'],
            'relatedbadgeids' => self::ids(''),
            'status' => ['type' => 'boolean'],
        ]);
        $award = [
            'badgeid' => ['type' => 'integer'],
            'recipientid' => ['type' => 'integer'],
            'issuerroleid' => ['type' => 'integer'],
        ];
        $recipient = self::obj(['badgeid' => $badgeid, 'recipientid' => self::id('Recipient user id.'),
            'issuerroleid' => self::id('Manual-award criteria role to award as.')], ['badgeid', 'recipientid']);

        return [
            self::def(
                'wrapper_badge_create_badge',
                'Create badge',
                'Create an inactive site badge, or a course badge when courseid is given.',
                ['moodle/badges:createbadge'],
                self::obj(['courseid' => self::id('Course id for a course badge.'), 'payload' => ['type' => 'object',
                    'description' => 'Fields: name, description, version, language, issuername, issuerurl, issuercontact, '
                        . 'imageauthorname, imageauthoremail, imageauthorurl, imagecaption, expiry (0|1 date|2 period), '
                        . 'expiredate, expireperiod, tags[].']], ['payload']),
                $badge,
                self::WRITE
            ),
            self::def(
                'wrapper_badge_update_badge',
                'Update badge',
                'Update details of an inactive, unlocked badge (same payload fields as create).',
                ['moodle/badges:configuredetails'],
                self::obj(['badgeid' => $badgeid, 'payload' => ['type' => 'object']], ['badgeid', 'payload']),
                $badge,
                self::WRITE
            ),
            self::def(
                'wrapper_badge_update_badge_message',
                'Update badge message',
                'Update the award message: messagesubject, message, messageformat, notification, attachment.',
                ['moodle/badges:configuremessages'],
                self::obj(['badgeid' => $badgeid, 'payload' => ['type' => 'object']], ['badgeid', 'payload']),
                $badge,
                self::WRITE
            ),
            self::def(
                'wrapper_badge_delete_badges',
                'Delete badges',
                'Delete badges. With archive=true (default) issued badges are kept for their recipients.',
                ['moodle/badges:deletebadge'],
                self::obj(
                    ['badgeids' => self::ids('Badge ids.'), 'archive' => ['type' => 'boolean', 'default' => true]],
                    ['badgeids']
                ),
                self::obj(['deleted' => ['type' => 'boolean'], 'badgeids' => self::ids('')]),
                self::DESTRUCTIVE
            ),
            self::def(
                'wrapper_badge_duplicate_badge',
                'Duplicate badge',
                'Copy a badge into a new inactive badge.',
                ['moodle/badges:createbadge', 'moodle/badges:configuredetails'],
                self::obj(['badgeid' => $badgeid], ['badgeid']),
                $badge,
                self::WRITE
            ),
            self::def(
                'wrapper_badge_add_related_badges',
                'Add related badges',
                'Link related badges to an inactive badge.',
                ['moodle/badges:configuredetails'],
                self::obj(['badgeid' => $badgeid, 'relatedbadgeids' => self::ids('Badge ids.')], ['badgeid', 'relatedbadgeids']),
                $relation,
                self::IDEMPOTENT_WRITE
            ),
            self::def(
                'wrapper_badge_delete_related_badges',
                'Remove related badges',
                'Remove related-badge links from an inactive badge.',
                ['moodle/badges:configuredetails'],
                self::obj(['badgeid' => $badgeid, 'relatedbadgeids' => self::ids('Badge ids.')], ['badgeid', 'relatedbadgeids']),
                $relation,
                self::DESTRUCTIVE
            ),
            self::def(
                'wrapper_badge_save_alignment',
                'Save badge alignment',
                'Create or update (with alignmentid) an alignment: targetname, targeturl, targetdescription, '
                    . 'targetframework, targetcode.',
                ['moodle/badges:configuredetails'],
                self::obj(
                    ['badgeid' => $badgeid, 'alignmentid' => self::id('Existing alignment id.'),
                    'payload' => ['type' => 'object']],
                    ['badgeid', 'payload']
                ),
                self::obj(['badgeid' => ['type' => 'integer'], 'alignmentid' => ['type' => 'integer'],
                    'status' => ['type' => 'boolean']]),
                self::WRITE
            ),
            self::def(
                'wrapper_badge_delete_alignments',
                'Delete badge alignments',
                'Delete alignment records from an inactive badge.',
                ['moodle/badges:configuredetails'],
                self::obj(['badgeid' => $badgeid, 'alignmentids' => self::ids('Alignment ids.')], ['badgeid', 'alignmentids']),
                self::obj(['badgeid' => ['type' => 'integer'], 'alignmentids' => self::ids(''), 'status' => ['type' => 'boolean']]),
                self::DESTRUCTIVE
            ),
            self::def(
                'wrapper_badge_award_badge',
                'Award badge',
                'Manually award an active badge with manual-award criteria to a user who can earn it.',
                ['moodle/badges:awardbadge'],
                $recipient,
                self::obj($award + ['awarded' => ['type' => 'boolean'], 'issued' => ['type' => ['boolean', 'null']]]),
                self::WRITE
            ),
            self::def(
                'wrapper_badge_revoke_badge',
                'Revoke badge',
                'Revoke a manual badge award from a user.',
                ['moodle/badges:revokebadge'],
                $recipient,
                self::obj($award + ['revoked' => ['type' => 'boolean']]),
                self::DESTRUCTIVE
            ),
        ];
    }

    /**
     * Activity and memory wrappers.
     *
     * @return definition[]
     */
    private static function module_and_memory_definitions(): array {
        $memory = self::obj([
            'id' => ['type' => 'integer'],
            'content' => ['type' => 'string'],
            'timecreated' => ['type' => 'integer'],
            'timemodified' => ['type' => 'integer'],
        ]);

        return [
            self::def(
                'wrapper_course_add_module',
                'Add activity or resource',
                'Create an activity or resource (e.g. url, page, label, forum, assign, quiz, resource, folder) in a course '
                    . 'section. Module-specific settings go in options using the module form field names, e.g. url: '
                    . '{externalurl, display}; page: {content, contentformat}; resource/folder: {files: <draftitemid>}. '
                    . 'Reserved keys (course, section, visible, name, intro, module, instance) must use the dedicated arguments.',
                ['moodle/course:manageactivities'],
                self::obj([
                    'courseid' => self::id('Course id.'),
                    'modulename' => ['type' => 'string', 'description' => 'Module plugin name without mod_, e.g. "url".'],
                    'name' => ['type' => 'string'],
                    'section' => ['type' => 'integer', 'default' => 0, 'description' => 'Section number (0 = general).'],
                    'visible' => ['type' => 'boolean', 'default' => true],
                    'intro' => ['type' => 'string', 'default' => '', 'description' => 'Description HTML.'],
                    'introformat' => ['type' => 'integer', 'default' => (int)FORMAT_HTML],
                    'options' => ['type' => 'object', 'description' => 'Extra module form fields.'],
                ], ['courseid', 'modulename', 'name']),
                self::obj([
                    'coursemodule' => ['type' => 'integer'],
                    'instance' => ['type' => 'integer'],
                    'modulename' => ['type' => 'string'],
                    'courseid' => ['type' => 'integer'],
                    'section' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'visible' => ['type' => 'boolean'],
                    'url' => ['type' => 'string'],
                ]),
                self::WRITE
            ),
            self::def(
                'wrapper_module_read_data',
                'Inspect activity',
                'Inspect a course module the user can see: course-module info (section, visibility, availability, '
                    . 'completion, url), formatted description, and for users who can manage activities the instance '
                    . 'settings (secrets removed) and file areas with file counts.',
                [],
                self::obj(['cmid' => self::id('Course module id.'), 'action' => ['type' => 'string',
                    'description' => 'Ignored; kept for compatibility.']], ['cmid']),
                self::obj([
                    'cm' => ['type' => 'object'],
                    'intro' => ['type' => 'string'],
                    'instance' => ['type' => ['object', 'null'], 'description' => 'Settings; null unless canmanage.'],
                    'fileareas' => ['type' => 'array', 'items' => self::obj([
                        'component' => ['type' => 'string'],
                        'filearea' => ['type' => 'string'],
                        'filecount' => ['type' => 'integer'],
                    ])],
                    'canmanage' => ['type' => 'boolean'],
                ]),
                self::READ
            ),
            self::def(
                'wrapper_memory_write',
                'Save memory',
                'Save a private note that persists across conversations for the current user (max 64 KB each, '
                    . '500 per user).',
                [],
                self::obj(['content' => ['type' => 'string']], ['content']),
                $memory,
                self::WRITE
            ),
            self::def(
                'wrapper_memory_read',
                'Read memories',
                'Read the current user\'s saved memories, or one memory by id.',
                [],
                self::obj(['id' => self::id('Memory id; omit to list.'), 'limit' => ['type' => 'integer', 'default' => 100],
                    'offset' => ['type' => 'integer', 'default' => 0]]),
                self::obj(['memories' => ['type' => 'array', 'items' => $memory], 'total' => ['type' => 'integer']]),
                self::READ
            ),
            self::def(
                'wrapper_memory_update',
                'Update memory',
                'Replace the content of one of the current user\'s memories.',
                [],
                self::obj(['id' => self::id('Memory id.'), 'content' => ['type' => 'string']], ['id', 'content']),
                $memory,
                self::IDEMPOTENT_WRITE
            ),
            self::def(
                'wrapper_memory_delete',
                'Delete memory',
                'Delete one of the current user\'s memories.',
                [],
                self::obj(['id' => self::id('Memory id.')], ['id']),
                self::obj(['deleted' => ['type' => 'boolean'], 'id' => ['type' => 'integer']]),
                self::DESTRUCTIVE
            ),
        ];
    }

    /**
     * Build a definition with webservice_mcp/operator defaults.
     *
     * @param string $name Tool name.
     * @param string $title Tool title.
     * @param string $description Description.
     * @param array $capabilities Discovery capabilities.
     * @param array $input Input schema.
     * @param array $output Output schema.
     * @param array $annotations Annotations.
     * @return definition
     */
    public static function def(
        string $name,
        string $title,
        string $description,
        array $capabilities,
        array $input,
        array $output,
        array $annotations
    ): definition {
        return new definition(
            $name,
            'webservice_mcp',
            'operator',
            $description,
            $capabilities,
            $input,
            $output,
            $annotations + ['openWorldHint' => false],
            $title
        );
    }

    /**
     * Object schema helper.
     *
     * @param array $properties Properties.
     * @param array $required Required keys.
     * @return array
     */
    public static function obj(array $properties, array $required = []): array {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * Integer id schema helper.
     *
     * @param string $description Description.
     * @return array
     */
    public static function id(string $description): array {
        return ['type' => 'integer', 'description' => $description];
    }

    /**
     * Integer list schema helper.
     *
     * @param string $description Description.
     * @return array
     */
    public static function ids(string $description): array {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];
        if ($description !== '') {
            $schema['description'] = $description;
        }
        return $schema;
    }
}
