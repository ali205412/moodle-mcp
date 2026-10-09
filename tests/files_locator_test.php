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
use webservice_mcp\local\files\locator;

/**
 * Tests for file URI and URL parsing.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\files\locator
 */
final class files_locator_test extends advanced_testcase {
    /**
     * Uri round trip with spaces and folders.
     */
    public function test_uri_round_trip_with_spaces_and_folders(): void {
        $uri = locator::uri(12, 'mod_folder', 'content', 0, '/week 1/', 'my notes.pdf');
        $this->assertSame('moodle://file/12/mod_folder/content/0/week%201/my%20notes.pdf', $uri);
        $this->assertSame(['contextid' => 12, 'component' => 'mod_folder', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/week 1/', 'filename' => 'my notes.pdf'], locator::parse_uri($uri));

        $dir = locator::parse_uri(locator::uri(12, 'user', 'private', 0, '/', '.'));
        $this->assertSame('/', $dir['filepath']);
        $this->assertSame('.', $dir['filename']);
    }

    /**
     * Bad uris are rejected.
     */
    public function test_bad_uris_are_rejected(): void {
        foreach (
            ['moodle://file/x/user/private/0/a', 'moodle://file/1/user/private/a/b', 'moodle://file/1/user',
                'moodle://file/1/user/private/0/../a', 'https://example.com/a', 'moodle://file/1/Bad Comp/private/0/a'] as $uri
        ) {
            try {
                locator::parse_uri($uri);
                $this->fail('Accepted ' . $uri);
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidparameter', $e->errorcode);
            }
        }
    }

    /**
     * Site urls of every file script.
     */
    public function test_site_urls_of_every_file_script(): void {
        global $CFG;
        $cases = [
            '/pluginfile.php/5/mod_resource/content/3/a%20b.pdf?forcedownload=1' => ['/5/mod_resource/content/3/a b.pdf', false],
            '/webservice/pluginfile.php/5/user/private/0/x.txt' => ['/5/user/private/0/x.txt', false],
            '/tokenpluginfile.php/abc123/5/course/overviewfiles/img.png' => ['/5/course/overviewfiles/img.png', false],
            '/draftfile.php/7/user/draft/99/f.txt' => ['/7/user/draft/99/f.txt', true],
            '/pluginfile.php?file=%2F5%2Fcourse%2Fsummary%2Fs.png' => ['/5/course/summary/s.png', false],
        ];
        foreach ($cases as $path => [$relativepath, $draft]) {
            $parsed = locator::parse_site_url($CFG->wwwroot . $path);
            $this->assertSame($relativepath, $parsed['relativepath'], $path);
            $this->assertSame($draft, $parsed['draft'], $path);
        }
        // Scheme differences do not matter; other hosts do.
        $httpurl = preg_replace('#^https?#', 'http', $CFG->wwwroot) . '/pluginfile.php/5/user/private/0/x.txt';
        $this->assertSame('/5/user/private/0/x.txt', locator::parse_site_url($httpurl)['relativepath']);
        $this->expectException(\moodle_exception::class);
        locator::parse_site_url('https://evil.example.org/pluginfile.php/5/user/private/0/x.txt');
    }

    /**
     * Relative path interpretation.
     */
    public function test_relative_path_interpretation(): void {
        $withitem = locator::params_from_relativepath('/5/mod_resource/content/3/sub/a.pdf', true);
        $this->assertSame(3, $withitem['itemid']);
        $this->assertSame('/sub/', $withitem['filepath']);
        $without = locator::params_from_relativepath('/5/course/summary/a.png', false);
        $this->assertSame(0, $without['itemid']);
        $this->assertNull(locator::params_from_relativepath('/5/course/summary/a.png', true));
        $this->assertSame('/5/mod_resource/content/3/sub/a.pdf', locator::relativepath($withitem));
    }
}
