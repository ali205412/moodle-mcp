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
use context_system;
use context_user;
use core_external\external_api;
use webservice_mcp\local\files\locator;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;
use webservice_mcp\local\ui\download_saver;

/**
 * Tests for saving non-HTML page responses into the user's draft area.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\ui\download_saver
 */
final class ui_download_saver_test extends advanced_testcase {
    /**
     * A CSV export becomes a draft file readable through the file tools.
     */
    public function test_save_to_draft(): void {
        $this->resetAfterTest();
        external_api::set_context_restriction(context_system::instance());
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $ctx = new call_context(call_context::ERA_MODERN, '2026-07-28', $user, null, null, true, 'files', [], 'f_test');

        $saved = download_saver::save(['body' => "name,score\nAna,9\n", 'contenttype' => 'text/csv; charset=utf-8',
            'filename' => 'grades.csv', 'url' => 'https://www.example.com/moodle/grade/export/txt/export.php'], $ctx);
        $this->assertSame('grades.csv', $saved['filename']);
        $this->assertSame('text/csv', $saved['mimetype']);
        $this->assertSame(17, $saved['size']);
        $params = locator::parse_uri($saved['uri']);
        $this->assertSame(
            [(int)context_user::instance($user->id)->id, 'user', 'draft', $saved['draftitemid']],
            [$params['contextid'], $params['component'], $params['filearea'], $params['itemid']]
        );
        $file = get_file_storage()->get_file($params['contextid'], 'user', 'draft', $params['itemid'], '/', 'grades.csv');
        $this->assertSame("name,score\nAna,9\n", $file->get_content());

        $this->expectException(transfer_exception::class);
        download_saver::save(['body' => '', 'contenttype' => 'application/pdf'], $ctx);
    }

    /**
     * File names come from Content-Disposition, else the URL, else "download", with an extension for the type.
     */
    public function test_filenames_and_detection(): void {
        $this->assertSame('report.pdf', download_saver::filename(['filename' => 'report.pdf', 'contenttype' => 'application/pdf']));
        $this->assertSame('marks.xlsx', download_saver::filename(['url' => 'https://x.example/files/marks.xlsx',
            'contenttype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
        $this->assertSame('download.csv', download_saver::filename(['url' => 'https://x.example/report/export.php?id=2',
            'contenttype' => 'text/csv']));
        $this->assertSame('download', download_saver::filename(['contenttype' => 'application/x-unknown-thing']));

        $this->assertFalse(download_saver::is_download(['contenttype' => 'text/html; charset=utf-8']));
        $this->assertTrue(download_saver::is_download(['contenttype' => 'application/pdf']));
        $this->assertTrue(download_saver::is_download(['contenttype' => 'text/html', 'filename' => 'page.html']));
    }
}
