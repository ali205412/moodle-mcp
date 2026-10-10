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
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\ui\form_submitter;
use webservice_mcp\local\ui\page_parser;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/ui_page_builder.php');

/**
 * Tests for building form submissions from parsed pages.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\ui\form_submitter
 */
final class ui_form_submitter_test extends advanced_testcase {
    /** @var array Parsed course settings form. */
    private $courseform;

    /** @var \stdClass */
    private $course;

    /**
     * Render and parse the course settings form as admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Biology 101', 'shortname' => 'BIO101']);
        $page = page_parser::parse(
            ui_page_builder::course_edit($this->course),
            (new \moodle_url('/course/edit.php', ['id' => $this->course->id]))->out(false)
        );
        $forms = array_filter($page['forms'], fn($f) => strpos($f['action'], '/course/edit.php') !== false);
        $this->courseform = array_values($forms)[0];
    }

    /**
     * Expect a validation error mentioning a text.
     *
     * @param array $form Form.
     * @param array $values Values.
     * @param string $needle Text expected in the message.
     * @param string|null $button Button.
     */
    private function assert_invalid(array $form, array $values, string $needle, ?string $button = null): void {
        try {
            form_submitter::build($form, $values, $button);
            $this->fail('Expected invalid values to be refused: ' . json_encode($values));
        } catch (transfer_exception $e) {
            $this->assertSame('invalidformvalue', $e->errorcode);
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * Untouched submission equals what a browser sends: hidden fields, current values, editor and date parts, primary button.
     */
    public function test_defaults(): void {
        $built = form_submitter::build($this->courseform, []);
        $fields = $built['fields'];
        $this->assertSame('post', $built['method']);
        $this->assertFalse($built['multipart']);
        $this->assertSame(sesskey(), $fields['sesskey']);
        $this->assertSame('Biology 101', $fields['fullname']);
        $this->assertSame((string)$this->course->id, $fields['id']);
        $this->assertArrayHasKey('summary_editor[text]', $fields);
        $this->assertArrayHasKey('summary_editor[format]', $fields);
        $this->assertArrayHasKey('summary_editor[itemid]', $fields);
        $this->assertArrayHasKey('startdate[year]', $fields);
        $this->assertArrayNotHasKey('enddate[enabled]', $fields);
        $this->assertSame('_qf__force_multiselect_submission', $fields['tags']);
        $this->assertSame('Save and display', $fields['saveanddisplay']);
        $this->assertArrayNotHasKey('cancel', $fields);
        $this->assertArrayNotHasKey('updatecourseformat', $fields);
        foreach ($fields as $value) {
            $this->assertIsString($value);
        }
    }

    /**
     * Values by name or label, options by value or label, editors, dates, file managers and tags.
     */
    public function test_overrides(): void {
        $draftitemid = file_get_unused_draft_itemid();
        $built = form_submitter::build($this->courseform, [
            'fullname' => 'Biology 102',
            'Course short name' => 'BIO102',
            'visible' => 'Hide',
            'showgrades' => false,
            'startdate' => '2027-01-15 09:30',
            'enddate' => mktime(12, 0, 0, 6, 30, 2027),
            'summary_editor' => ['text' => '<p>New summary</p>', 'format' => 'HTML format'],
            'overviewfiles_filemanager' => $draftitemid,
            'tags[]' => ['science', 'year 1'],
        ]);
        $fields = $built['fields'];
        $this->assertSame('Biology 102', $fields['fullname']);
        $this->assertSame('BIO102', $fields['shortname']);
        $this->assertSame('0', $fields['visible']);
        $this->assertSame('0', $fields['showgrades']);
        $this->assertSame(['15', '1', '2027', '9', '30'], [$fields['startdate[day]'], $fields['startdate[month]'],
            $fields['startdate[year]'], $fields['startdate[hour]'], $fields['startdate[minute]']]);
        $end = usergetdate(mktime(12, 0, 0, 6, 30, 2027));
        $this->assertSame('1', $fields['enddate[enabled]']);
        $this->assertSame((string)$end['mday'], $fields['enddate[day]']);
        $this->assertSame('<p>New summary</p>', $fields['summary_editor[text]']);
        $this->assertSame('1', $fields['summary_editor[format]']);
        $this->assertSame((string)$draftitemid, $fields['overviewfiles_filemanager']);
        $this->assertSame('science', $fields['tags[0]']);
        $this->assertSame('year 1', $fields['tags[1]']);

        // Turning an optional date off again, and choosing another button.
        $built = form_submitter::build($this->courseform, ['enddate' => null], 'Cancel');
        $this->assertArrayNotHasKey('enddate[enabled]', $built['fields']);
        $this->assertSame('Cancel', $built['fields']['cancel']);
        $this->assertArrayNotHasKey('saveanddisplay', $built['fields']);
    }

    /**
     * Invalid input is refused with the allowed alternatives named.
     */
    public function test_validation(): void {
        $this->assert_invalid($this->courseform, ['fulname' => 'x'], 'fullname (Course full name)');
        $this->assert_invalid($this->courseform, ['visible' => 'Maybe'], '0 (Hide), 1 (Show)');
        $this->assert_invalid($this->courseform, ['startdate' => ['year' => 1066, 'month' => 1, 'day' => 1]], 'outside the range');
        $this->assert_invalid($this->courseform, ['startdate' => ['year' => 2027, 'month' => 2, 'day' => 30]], 'not a valid date');
        $this->assert_invalid($this->courseform, ['startdate' => 'next blursday'], 'needs a timestamp');
        $this->assert_invalid($this->courseform, ['startdate' => null], 'cannot be disabled');
        $this->assert_invalid($this->courseform, ['overviewfiles_filemanager' => 'cat.png'], 'draftitemid');
        $this->assert_invalid($this->courseform, ['summary_editor' => ['html' => 'x']], 'text, format and itemid');
        $this->assert_invalid($this->courseform, ['fullname' => ['a', 'b']], 'single value');
        $this->assert_invalid($this->courseform, [], 'Buttons: ', 'Publish');
    }

    /**
     * Plain HTML forms: radios, checkbox groups, plain checkboxes, passwords, disabled fields, multipart.
     */
    public function test_plain_form(): void {
        $page = page_parser::parse(
            file_get_contents(__DIR__ . '/fixtures/ui/edge_cases.html'),
            'https://moodle.example.org/sub/mod/forum/post.php?reply=5'
        );
        $form = $page['forms'][0];

        $defaults = form_submitter::build($form, []);
        $this->assertTrue($defaults['multipart']);
        $this->assertEquals(['sesskey' => 'abcDEF1234', 'd' => '5', 'subject' => 'Re: hello', 'mood' => 'c', 'notused' => 'a',
            'opts[0]' => 'red', 'message' => "Hello\nthere", 'outside' => 'o', 'size' => 'l'], $defaults['fields']);
        $this->assertArrayNotHasKey('password', $defaults['fields']);
        $this->assertArrayNotHasKey('locked', $defaults['fields']);

        $built = form_submitter::build($form, ['size' => 'Small', 'opts[]' => ['blue', 'Red'], 'agree' => true,
            'password' => 'pw', 'mood' => 'Bad / Sad'], 'Save draft');
        $fields = $built['fields'];
        $this->assertSame('s', $fields['size']);
        $this->assertSame('red', $fields['opts[0]']);
        $this->assertSame('blue', $fields['opts[1]']);
        $this->assertSame('on', $fields['agree']);
        $this->assertSame('pw', $fields['password']);
        $this->assertSame('s', $fields['mood']);
        $this->assertSame('1', $fields['draft']);

        $this->assert_invalid($form, ['locked' => 'y'], 'disabled');
        $this->assert_invalid($form, ['attachment' => 'x'], 'browser file upload');
        $this->assert_invalid($form, ['agree' => 'perhaps'], 'true or false');
    }

    /**
     * Admin settings: checkboxes send their unchecked value; passwords are left alone unless given.
     */
    public function test_admin_settings(): void {
        $page = page_parser::parse(
            ui_page_builder::admin_settings('sitepolicies'),
            (new \moodle_url('/admin/settings.php', ['section' => 'sitepolicies']))->out(false)
        );
        $form = array_values(array_filter($page['forms'], fn($f) => strpos($f['action'], '/admin/settings.php') !== false))[0];
        $fields = form_submitter::build($form, ['s__allowobjectembed' => true, 'Protect usernames' => 'no'])['fields'];
        $this->assertSame('1', $fields['s__allowobjectembed']);
        $this->assertSame('0', $fields['s__protectusernames']);
        $this->assertSame('sitepolicies', $fields['section']);
        $this->assertArrayNotHasKey('s__cronremotepassword', $fields);
    }
}
