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

namespace webservice_mcp;

use advanced_testcase;
use context_course;
use context_module;
use context_system;
use context_user;
use core_external\external_api;
use webservice_mcp\local\files\file_service;
use webservice_mcp\local\files\locator;
use webservice_mcp\local\files\tools;
use webservice_mcp\local\mcp\call_context;

/**
 * Tests for permission-checked file listing and reading.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\files\lister
 * @covers      \webservice_mcp\local\files\file_reader
 * @covers      \webservice_mcp\local\files\locator
 * @covers      \webservice_mcp\local\files\tools
 */
final class files_access_test extends advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $teacher;

    /** @var \stdClass */
    private $student;

    /** @var \stdClass */
    private $outsider;

    /** @var \stdClass Folder module record. */
    private $folder;

    /**
     * Course with a folder holding notes.txt and a resource; a teacher, a student and an outsider.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        external_api::set_context_restriction(context_system::instance());
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $this->outsider = $generator->create_and_enrol($generator->create_course(), 'student');

        $this->setUser($this->teacher);
        $this->folder = $generator->create_module('folder', ['course' => $this->course->id, 'name' => 'Week 1']);
        $generator->create_module('resource', ['course' => $this->course->id, 'name' => 'Syllabus']);
        get_file_storage()->create_file_from_string(['contextid' => context_module::instance($this->folder->cmid)->id,
            'component' => 'mod_folder', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'notes.txt'], 'hello world');
        $this->setUser(null);
    }

    /**
     * Call a tool as a user.
     *
     * @param \stdClass $user User.
     * @param string $name Tool.
     * @param array $args Arguments.
     * @param \context|null $restriction Context restriction.
     * @param int|null $serviceid External service id.
     * @return array
     */
    private function call(
        \stdClass $user,
        string $name,
        array $args,
        ?\context $restriction = null,
        ?int $serviceid = null
    ): array {
        $this->setUser($user);
        // Family key is only needed to mint links here; redeeming is covered in files_tickets_test.
        $ctx = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $user,
            $restriction,
            $serviceid,
            true,
            'files',
            [],
            'f_test'
        );
        return tools::execute($name, $args, $ctx);
    }

    /**
     * URI of the folder's notes.txt.
     *
     * @return string
     */
    private function notes_uri(): string {
        return locator::uri(context_module::instance($this->folder->cmid)->id, 'mod_folder', 'content', 0, '/', 'notes.txt');
    }

    /**
     * Store a private file for a user.
     *
     * @param \stdClass $user Owner.
     * @param string $filename Name.
     * @param string|null $content Content, or null to copy $path.
     * @param string|null $path Source path.
     * @return \stored_file
     */
    private function private_file(\stdClass $user, string $filename, ?string $content, ?string $path = null): \stored_file {
        $record = ['contextid' => context_user::instance($user->id)->id, 'component' => 'user', 'filearea' => 'private',
            'itemid' => 0, 'filepath' => '/', 'filename' => $filename, 'userid' => $user->id];
        $fs = get_file_storage();
        return $content !== null ? $fs->create_file_from_string($record, $content) : $fs->create_file_from_pathname($record, $path);
    }

    /**
     * Default listing shows own areas and limits.
     */
    public function test_default_listing_shows_own_areas_and_limits(): void {
        $this->private_file($this->student, 'mine.txt', 'x');
        $result = $this->call($this->student, 'file_list', [])['structuredContent'];
        $this->assertSame('context', $result['node']['type']);
        $this->assertArrayHasKey('maxuploadbytes', $result['limits']);
        $this->assertContains('private', array_column($result['entries'], 'filearea'));

        $files = $this->call($this->student, 'file_list', ['component' => 'user', 'filearea' => 'private'])['structuredContent'];
        $this->assertSame(['mine.txt'], array_column($files['entries'], 'filename'));
        $this->assertStringStartsWith('moodle://file/', $files['entries'][0]['uri']);
    }

    /**
     * Recursive course listing includes activity files for students.
     */
    public function test_recursive_course_listing_includes_activity_files_for_students(): void {
        $result = $this->call($this->student, 'file_list', ['courseid' => $this->course->id, 'recursive' => true]);
        $files = array_filter($result['structuredContent']['entries'], fn($e) => $e['type'] === 'file');
        $names = array_column($files, 'filename');
        $this->assertContains('notes.txt', $names);
        $this->assertContains('resource1.txt', $names);
        foreach ($files as $file) {
            $this->assertNotEmpty($file['cmid']);
            $this->assertStringContainsString('/webservice/pluginfile.php/', $file['url']);
        }
    }

    /**
     * Course listing denied to non members.
     */
    public function test_course_listing_denied_to_non_members(): void {
        $this->expectException(\moodle_exception::class);
        $this->call($this->outsider, 'file_list', ['courseid' => $this->course->id]);
    }

    /**
     * Module listing and inline text read.
     */
    public function test_module_listing_and_inline_text_read(): void {
        $listing = $this->call($this->student, 'file_list', ['cmid' => $this->folder->cmid, 'recursive' => true]);
        $notes = array_values(array_filter(
            $listing['structuredContent']['entries'],
            fn($e) => ($e['uri'] ?? '') === $this->notes_uri()
        ));
        $this->assertCount(1, $notes);
        $this->assertFalse($notes[0]['writable']);
        $this->assertSame(11, $notes[0]['size']);

        $read = $this->call($this->student, 'file_read', ['uri' => $this->notes_uri()]);
        $this->assertSame('hello world', $read['content'][1]['text']);

        $slice = $this->call($this->student, 'file_read', ['uri' => $this->notes_uri(), 'offset' => 6, 'length' => 5]);
        $this->assertSame('world', $slice['content'][1]['text']);
    }

    /**
     * Text over cap is paged with download hint.
     */
    public function test_text_over_cap_is_paged_with_download_hint(): void {
        set_config('inlinetextmaxbytes', 5, 'webservice_mcp');
        $read = $this->call($this->student, 'file_read', ['uri' => $this->notes_uri()]);
        $this->assertSame('hello', $read['content'][1]['text']);
        $this->assertStringContainsString('offset=5', $read['content'][2]['text']);
        $this->assertStringContainsString('/webservice/mcp/pluginfile.php?ticket=', $read['content'][2]['text']);
    }

    /**
     * Text pages split on utf8 boundaries.
     */
    public function test_text_pages_split_on_utf8_boundaries(): void {
        $file = $this->private_file($this->student, 'accents.txt', 'aééé');
        set_config('inlinetextmaxbytes', 4, 'webservice_mcp');
        $read = $this->call($this->student, 'file_read', ['uri' => locator::uri_for($file)]);
        $this->assertSame('aé', $read['content'][1]['text']);
        $this->assertStringContainsString('offset=3', $read['content'][2]['text']);
        $next = $this->call($this->student, 'file_read', ['uri' => locator::uri_for($file), 'offset' => 3]);
        $this->assertSame('éé', $next['content'][1]['text']);
    }

    /**
     * Students read activity files without loopback.
     */
    public function test_students_read_activity_files_without_loopback(): void {
        // The mod_resource module hides its files from file_browser for students; the module's own export_contents listing
        // (what core_course_get_contents shows) is used in-process instead of an HTTP self-request.
        $listing = $this->call($this->student, 'file_list', ['courseid' => $this->course->id, 'recursive' => true]);
        $resource = array_values(array_filter(
            $listing['structuredContent']['entries'],
            fn($e) => ($e['filename'] ?? '') === 'resource1.txt'
        ));
        $read = $this->call($this->student, 'file_read', ['uri' => $resource[0]['uri']]);
        $this->assertSame('Test resource resource1.txt file', $read['content'][1]['text']);
        $byurl = $this->call($this->student, 'file_read', ['url' => $resource[0]['url']]);
        $this->assertSame('Test resource resource1.txt file', $byurl['content'][1]['text']);
    }

    /**
     * Areas outside file browser get a link not a fetch.
     */
    public function test_areas_outside_file_browser_get_a_link_not_a_fetch(): void {
        $uri = locator::uri(context_course::instance($this->course->id)->id, 'question', 'questiontext', 1, '/', 'q.png');
        $read = $this->call($this->student, 'file_read', ['uri' => $uri]);
        $this->assertStringContainsString('size unknown', $read['content'][0]['text']);
        $this->assertStringContainsString('/webservice/mcp/pluginfile.php?ticket=', $read['content'][0]['text']);
    }

    /**
     * Reads need the service download flag.
     */
    public function test_reads_need_the_service_download_flag(): void {
        global $DB;
        $serviceid = (int)$DB->insert_record('external_services', ['name' => 'nodl', 'shortname' => 'nodl', 'enabled' => 1,
            'requiredcapability' => '', 'restrictedusers' => 0, 'downloadfiles' => 0, 'uploadfiles' => 1,
            'timecreated' => time()]);
        try {
            $this->call($this->student, 'file_read', ['uri' => $this->notes_uri()], null, $serviceid);
            $this->fail('Read allowed with downloads disabled.');
        } catch (\webservice_mcp\local\files\transfer_exception $e) {
            $this->assertSame(403, $e->status);
        }
        $this->setUser($this->student);
        $ctx = new call_context(
            call_context::ERA_MODERN,
            '2026-07-28',
            $this->student,
            null,
            $serviceid,
            true,
            'files',
            [],
            'f_test'
        );
        $this->assertNotContains('file_read', array_column(tools::describe($ctx), 'name'));
        $this->expectException(\webservice_mcp\local\files\transfer_exception::class);
        (new file_service())->read_resource($this->notes_uri(), $ctx);
    }

    /**
     * Outsider cannot read module file.
     */
    public function test_outsider_cannot_read_module_file(): void {
        $this->expectException(\moodle_exception::class);
        $this->call($this->outsider, 'file_read', ['uri' => $this->notes_uri()]);
    }

    /**
     * Image and binary inline and cap fallback.
     */
    public function test_image_and_binary_inline_and_cap_fallback(): void {
        global $CFG;
        $image = $this->private_file($this->student, 'logo.png', null, $CFG->dirroot . '/lib/tests/fixtures/gd-logo.png');
        $read = $this->call($this->student, 'file_read', ['uri' => locator::uri_for($image)]);
        $this->assertSame('image', $read['content'][1]['type']);
        $this->assertSame('image/png', $read['content'][1]['mimeType']);
        $this->assertSame($image->get_content(), base64_decode($read['content'][1]['data']));

        $bin = $this->private_file($this->student, 'data.bin', random_bytes(100));
        $read = $this->call($this->student, 'file_read', ['uri' => locator::uri_for($bin)]);
        $this->assertSame('resource', $read['content'][1]['type']);
        $this->assertSame($bin->get_content(), base64_decode($read['content'][1]['resource']['blob']));

        set_config('inlinebinarymaxbytes', 10, 'webservice_mcp');
        $read = $this->call($this->student, 'file_read', ['uri' => locator::uri_for($bin)]);
        $this->assertStringContainsString('Too large', $read['content'][0]['text']);
        $this->assertSame('resource_link', $read['content'][1]['type']);
    }

    /**
     * Read by site url and resource read.
     */
    public function test_read_by_site_url_and_resource_read(): void {
        $file = $this->private_file($this->student, 'url.txt', 'via url');
        $url = \moodle_url::make_pluginfile_url($file->get_contextid(), 'user', 'private', 0, '/', 'url.txt')->out(false);
        $read = $this->call($this->student, 'file_read', ['url' => $url]);
        $this->assertSame('via url', $read['content'][1]['text']);

        $ctx = new call_context(call_context::ERA_MODERN, '2026-07-28', $this->student, null, null, true, 'files', [], 'f_test');
        $resource = (new file_service())->read_resource(locator::uri_for($file), $ctx);
        $this->assertSame('via url', $resource['contents'][0]['text']);
    }

    /**
     * Other users private files are not readable.
     */
    public function test_other_users_private_files_are_not_readable(): void {
        $file = $this->private_file($this->teacher, 'secret.txt', 'secret');
        $this->expectException(\moodle_exception::class);
        $this->call($this->student, 'file_read', ['uri' => locator::uri_for($file)]);
    }

    /**
     * Course restricted token cannot reach other contexts.
     */
    public function test_course_restricted_token_cannot_reach_other_contexts(): void {
        $other = $this->getDataGenerator()->create_course();
        $restriction = context_course::instance($other->id);
        $this->getDataGenerator()->enrol_user($this->student->id, $other->id, 'student');

        $this->expectException(\core_external\restricted_context_exception::class);
        $this->call($this->student, 'file_read', ['uri' => $this->notes_uri()], $restriction);
    }

    /**
     * Invalid arguments are rejected.
     */
    public function test_invalid_arguments_are_rejected(): void {
        try {
            $this->call($this->student, 'file_read', ['uri' => $this->notes_uri(), 'bogus' => 1]);
            $this->fail('Unknown argument accepted.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('bogus', $e->debuginfo);
        }
        $this->expectException(\moodle_exception::class);
        $this->call($this->student, 'file_list', ['limit' => 'many']);
    }
}
