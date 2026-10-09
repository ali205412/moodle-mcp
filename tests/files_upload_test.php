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
use webservice_mcp\local\files\limits;
use webservice_mcp\local\files\locator;
use webservice_mcp\local\files\tools;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\files\upload_handler;
use webservice_mcp\local\mcp\call_context;

/**
 * Tests for draft uploads, saving drafts, deletes and course images.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\files\file_service
 * @covers      \webservice_mcp\local\files\upload_handler
 */
final class files_upload_test extends advanced_testcase {
    /** @var \stdClass */
    private $user;

    /** @var call_context */
    private $ctx;

    /**
     * Reset state and log a user in.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        external_api::set_context_restriction(context_system::instance());
        $this->user = $this->getDataGenerator()->create_user();
        $this->login($this->user);
    }

    /**
     * Become a user.
     *
     * @param \stdClass $user User.
     */
    private function login(\stdClass $user): void {
        $this->setUser($user);
        // Family key is only needed to mint links here; redeeming is covered in files_tickets_test.
        $this->ctx = new call_context(call_context::ERA_MODERN, '2026-07-28', $user, null, null, true, 'files', [], 'f_test');
    }

    /**
     * Write a temp file.
     *
     * @param string $content Content.
     * @return string Path.
     */
    private function temp(string $content): string {
        $path = make_request_directory() . '/' . random_string(8);
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Upload text into a draft area through the tool.
     *
     * @param string $filename Name.
     * @param string $content Content.
     * @param int $draftitemid Draft id or 0.
     * @return array Structured result.
     */
    private function upload(string $filename, string $content, int $draftitemid = 0): array {
        $args = ['filename' => $filename, 'content_text' => $content] + ($draftitemid ? ['draftitemid' => $draftitemid] : []);
        return tools::execute('file_upload', $args, $this->ctx)['structuredContent'];
    }

    /**
     * Store draft file renames or overwrites duplicates.
     */
    public function test_store_draft_file_renames_or_overwrites_duplicates(): void {
        $first = (new file_service())->store_draft_file($this->temp('one'), 'a.txt', [], $this->ctx);
        $this->assertGreaterThan(0, $first['draftitemid']);
        $this->assertSame(locator::uri(
            context_user::instance($this->user->id)->id,
            'user',
            'draft',
            $first['draftitemid'],
            '/',
            'a.txt'
        ), $first['uri']);

        $renamed = (new file_service())->store_draft_file(
            $this->temp('two'),
            'a.txt',
            ['draftitemid' => $first['draftitemid']],
            $this->ctx
        );
        $this->assertSame('a (1).txt', $renamed['filename']);
        $this->assertSame('a.txt', $renamed['renamedfrom']);

        (new file_service())->store_draft_file(
            $this->temp('three'),
            'a.txt',
            ['draftitemid' => $first['draftitemid'], 'overwrite' => true],
            $this->ctx
        );
        $file = get_file_storage()->get_file(
            context_user::instance($this->user->id)->id,
            'user',
            'draft',
            $first['draftitemid'],
            '/',
            'a.txt'
        );
        $this->assertSame('three', $file->get_content());
        $this->assertSame(fullname($this->user), $file->get_author());
    }

    /**
     * Store draft file enforces size limit.
     */
    public function test_store_draft_file_enforces_size_limit(): void {
        $this->expectException(\file_exception::class);
        (new file_service())->store_draft_file($this->temp('too big'), 'b.txt', ['maxbytes' => 3], $this->ctx);
    }

    /**
     * Store draft file runs antivirus.
     */
    public function test_store_draft_file_runs_antivirus(): void {
        global $CFG;
        // Offline scanner double: the core testable scanner's incident report does a network IP lookup.
        require_once($CFG->dirroot . '/webservice/mcp/tests/fixtures/files_antivirus_scanner.php');
        $CFG->antiviruses = 'mcpfilestest';

        $clean = (new file_service())->store_draft_file($this->temp('clean'), 'clean.txt', [], $this->ctx);
        $this->assertSame('clean.txt', $clean['filename']);

        $path = $this->temp('eicar');
        try {
            (new file_service())->store_draft_file($path, 'INFECTED.txt', ['draftitemid' => $clean['draftitemid']], $this->ctx);
            $this->fail('Infected file stored.');
        } catch (\core\antivirus\scanner_exception $e) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertFalse(get_file_storage()->file_exists(
            context_user::instance($this->user->id)->id,
            'user',
            'draft',
            $clean['draftitemid'],
            '/',
            'INFECTED.txt'
        ));
    }

    /**
     * Inline upload base64 text and limits.
     */
    public function test_inline_upload_base64_text_and_limits(): void {
        $stored = tools::execute(
            'file_upload',
            ['filename' => 'b.bin', 'content_base64' => base64_encode("\x00\x01binary")],
            $this->ctx
        )['structuredContent'];
        $file = get_file_storage()->get_file(
            context_user::instance($this->user->id)->id,
            'user',
            'draft',
            $stored['draftitemid'],
            '/',
            'b.bin'
        );
        $this->assertSame("\x00\x01binary", $file->get_content());

        $listing = tools::execute('file_list', ['draftitemid' => $stored['draftitemid']], $this->ctx)['structuredContent'];
        $this->assertSame(['b.bin'], array_column($listing['entries'], 'filename'));

        try {
            tools::execute('file_upload', ['filename' => 'x', 'content_base64' => '***'], $this->ctx);
            $this->fail('Invalid base64 accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidparameter', $e->errorcode);
        }

        set_config('uploadinlinemaxbytes', 4, 'webservice_mcp');
        $this->expectException(transfer_exception::class);
        $this->upload('big.txt', 'more than four bytes');
    }

    /**
     * Endpoint stream whole body and limit.
     */
    public function test_endpoint_stream_whole_body_and_limit(): void {
        $claims = ['u' => (int)$this->user->id, 'd' => file_get_unused_draft_itemid(), 'fp' => '/docs/', 'mb' => 20, 'ow' => 0];
        $in = fopen('php://memory', 'w+b');
        fwrite($in, 'streamed body');
        rewind($in);
        $result = upload_handler::receive_stream($in, null, 'body.txt', $claims, $this->ctx);
        $this->assertTrue($result['complete']);
        $this->assertSame($claims['d'], $result['draftitemid']);
        $this->assertSame('/docs/', $result['files'][0]['filepath']);
        $this->assertSame(13, $result['files'][0]['size']);

        $in = fopen('php://memory', 'w+b');
        fwrite($in, str_repeat('x', 21));
        rewind($in);
        try {
            upload_handler::receive_stream($in, null, 'big.txt', $claims, $this->ctx);
            $this->fail('Oversized body accepted.');
        } catch (transfer_exception $e) {
            $this->assertSame(413, $e->status);
        }
    }

    /**
     * Endpoint resumable chunks.
     */
    public function test_endpoint_resumable_chunks(): void {
        $claims = ['u' => (int)$this->user->id, 'd' => file_get_unused_draft_itemid(), 'fp' => '/', 'mb' => -1, 'ow' => 0];
        $chunk = function (string $data) {
            $in = fopen('php://memory', 'w+b');
            fwrite($in, $data);
            rewind($in);
            return $in;
        };

        $first = upload_handler::receive_stream($chunk('hello '), 'bytes 0-5/11', 'big.txt', $claims, $this->ctx);
        $this->assertFalse($first['complete']);
        $this->assertSame(6, $first['received']);

        try {
            upload_handler::receive_stream($chunk('xx'), 'bytes 2-3/11', 'big.txt', $claims, $this->ctx);
            $this->fail('Out-of-order chunk accepted.');
        } catch (transfer_exception $e) {
            $this->assertSame(416, $e->status);
        }

        $done = upload_handler::receive_stream($chunk('world'), 'bytes 6-10/11', 'big.txt', $claims, $this->ctx);
        $this->assertTrue($done['complete']);
        $file = get_file_storage()->get_file(
            context_user::instance($this->user->id)->id,
            'user',
            'draft',
            $claims['d'],
            '/',
            'big.txt'
        );
        $this->assertSame('hello world', $file->get_content());
    }

    /**
     * With no site limit the plugin's 2 GB default applies (not PHP's form-upload limit); smaller limits win.
     */
    public function test_upload_limit_defaults_to_plugin_setting_not_php_limit(): void {
        global $CFG;
        $usercontext = context_user::instance($this->user->id);
        $course = $this->getDataGenerator()->create_course(['maxbytes' => 0]);
        $CFG->maxbytes = 0;

        // No site or course limit: the plugin's 2 GB default, not PHP's (often 2 MB) upload_max_filesize.
        $this->assertSame(2147483648, limits::max_upload_bytes($usercontext));
        set_config('uploadmaxbytes', 1000, 'webservice_mcp');
        $this->assertSame(1000, limits::max_upload_bytes(context_course::instance($course->id)));

        // A site or course limit applies as in core, whatever the plugin setting.
        $CFG->maxbytes = 5000;
        $this->assertSame(5000, limits::max_upload_bytes($usercontext));
        $limited = $this->getDataGenerator()->create_course(['maxbytes' => 300]);
        $this->assertSame(300, limits::max_upload_bytes(context_course::instance($limited->id)));
    }

    /**
     * Chunked uploads are capped per user.
     */
    public function test_chunked_uploads_are_capped_per_user(): void {
        $claims = ['u' => (int)$this->user->id, 'd' => file_get_unused_draft_itemid(), 'fp' => '/', 'mb' => 100, 'ow' => 0];
        $chunk = function (string $data) {
            $in = fopen('php://memory', 'w+b');
            fwrite($in, $data);
            rewind($in);
            return $in;
        };
        $status = function (callable $call): int {
            try {
                $call();
            } catch (transfer_exception $e) {
                return $e->status;
            }
            return 0;
        };

        // A declared total above the limit is refused before any data is read.
        $this->assertSame(413, $status(fn() => upload_handler::receive_stream(
            $chunk('ab'),
            'bytes 0-1/101',
            'huge.bin',
            $claims,
            $this->ctx
        )));

        set_config('uploadmaxpartials', 1, 'webservice_mcp');
        upload_handler::receive_stream($chunk('ab'), 'bytes 0-1/10', 'one.bin', $claims, $this->ctx);
        $this->assertSame(429, $status(fn() => upload_handler::receive_stream(
            $chunk('ab'),
            'bytes 0-1/10',
            'two.bin',
            $claims,
            $this->ctx
        )));
        // Continuing the open upload is still allowed.
        $more = upload_handler::receive_stream($chunk('cd'), 'bytes 2-3/10', 'one.bin', $claims, $this->ctx);
        $this->assertSame(4, $more['received']);

        set_config('uploadmaxpartials', 5, 'webservice_mcp');
        set_config('uploadmaxpartialbytes', 15, 'webservice_mcp');
        $this->assertSame(413, $status(fn() => upload_handler::receive_stream(
            $chunk('ab'),
            'bytes 0-1/6',
            'three.bin',
            $claims,
            $this->ctx
        )));
        $this->assertSame(0, $status(fn() => upload_handler::receive_stream(
            $chunk('ab'),
            'bytes 0-1/5',
            'four.bin',
            $claims,
            $this->ctx
        )));
    }

    /**
     * Endpoint multipart.
     */
    public function test_endpoint_multipart(): void {
        $claims = ['d' => file_get_unused_draft_itemid(), 'fp' => '/', 'mb' => -1, 'ow' => 0];
        $files = ['file' => ['name' => ['a.txt', 'b.txt'], 'tmp_name' => [$this->temp('A'), $this->temp('BB')],
            'size' => [1, 2], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK], 'type' => ['text/plain', 'text/plain']]];
        $result = upload_handler::receive_multipart($files, $claims, $this->ctx);
        $this->assertSame(['a.txt', 'b.txt'], array_column($result['files'], 'filename'));
    }

    /**
     * Save draft into private files respects quota.
     */
    public function test_save_draft_into_private_files_respects_quota(): void {
        global $CFG;
        $draft = $this->upload('notes.txt', 'private notes')['draftitemid'];
        $saved = tools::execute('file_save_draft', ['draftitemid' => $draft], $this->ctx)['structuredContent'];
        $this->assertSame(['notes.txt'], array_column($saved['files'], 'filename'));
        $this->assertTrue(get_file_storage()->file_exists(
            context_user::instance($this->user->id)->id,
            'user',
            'private',
            0,
            '/',
            'notes.txt'
        ));

        $CFG->userquota = 20;
        $draft = $this->upload('more.txt', 'this pushes the quota over')['draftitemid'];
        $this->expectException(\file_exception::class);
        tools::execute('file_save_draft', ['draftitemid' => $draft], $this->ctx);
    }

    /**
     * Save draft requires write access.
     */
    public function test_save_draft_requires_write_access(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $folder = $generator->create_module('folder', ['course' => $course->id]);
        $target = ['cmid' => $folder->cmid, 'component' => 'mod_folder', 'filearea' => 'content'];

        $this->login($teacher);
        $draft = $this->upload('handout.txt', 'handout')['draftitemid'];
        $saved = tools::execute('file_save_draft', ['draftitemid' => $draft] + $target, $this->ctx)['structuredContent'];
        $this->assertContains('handout.txt', array_column($saved['files'], 'filename'));

        $this->login($student);
        $draft = $this->upload('sneaky.txt', 'nope')['draftitemid'];
        try {
            tools::execute('file_save_draft', ['draftitemid' => $draft] + $target, $this->ctx);
            $this->fail('Student wrote into a folder.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $uri = locator::uri(context_module::instance($folder->cmid)->id, 'mod_folder', 'content', 0, '/', 'handout.txt');
        try {
            tools::execute('file_delete', ['uri' => $uri], $this->ctx);
            $this->fail('Student deleted a folder file.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->login($teacher);
        $this->assertTrue(tools::execute('file_delete', ['uri' => $uri], $this->ctx)['structuredContent']['deleted']);
        $this->assertFalse(get_file_storage()->file_exists(
            context_module::instance($folder->cmid)->id,
            'mod_folder',
            'content',
            0,
            '/',
            'handout.txt'
        ));
    }

    /**
     * Delete refuses area root.
     */
    public function test_delete_refuses_area_root(): void {
        $this->expectException(\moodle_exception::class);
        tools::execute('file_delete', ['uri' => locator::uri(
            context_user::instance($this->user->id)->id,
            'user',
            'private',
            0,
            '/',
            '.'
        )], $this->ctx);
    }

    /**
     * Set course image.
     */
    public function test_set_course_image(): void {
        global $CFG;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $image = base64_encode(file_get_contents($CFG->dirroot . '/lib/tests/fixtures/gd-logo.png'));

        $this->login($teacher);
        $draft = tools::execute(
            'file_upload',
            ['filename' => 'cover.png', 'content_base64' => $image],
            $this->ctx
        )['structuredContent']['draftitemid'];
        $result = tools::execute(
            'file_set_course_image',
            ['courseid' => $course->id, 'draftitemid' => $draft],
            $this->ctx
        )['structuredContent'];
        $this->assertSame(['cover.png'], array_column($result['files'], 'filename'));
        $this->assertTrue(get_file_storage()->file_exists(
            context_course::instance($course->id)->id,
            'course',
            'overviewfiles',
            0,
            '/',
            'cover.png'
        ));

        $this->login($student);
        $draft = tools::execute(
            'file_upload',
            ['filename' => 'x.png', 'content_base64' => $image],
            $this->ctx
        )['structuredContent']['draftitemid'];
        $this->expectException(\required_capability_exception::class);
        tools::execute('file_set_course_image', ['courseid' => $course->id, 'draftitemid' => $draft], $this->ctx);
    }

    /**
     * Create upload url allocates draft.
     */
    public function test_create_upload_url_allocates_draft(): void {
        $result = tools::execute('file_create_upload_url', ['filename' => 'big.iso'], $this->ctx)['structuredContent'];
        $this->assertGreaterThan(0, $result['draftitemid']);
        $this->assertStringContainsString('/webservice/mcp/upload.php?ticket=', $result['url']);
        $this->assertStringContainsString('curl -fS -T', $result['curl']);
    }
}
