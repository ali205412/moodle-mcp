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
use webservice_mcp\local\ui\page_parser;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/ui_page_builder.php');

/**
 * Tests for the UI bridge page parser, on pages rendered by the installed Moodle and on saved pages.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\ui\page_parser
 * @covers      \webservice_mcp\local\ui\form_parser
 */
final class ui_page_parser_test extends advanced_testcase {
    /** @var \stdClass */
    private $course;

    /**
     * A course with two activities, as admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['fullname' => 'Biology 101', 'shortname' => 'BIO101']);
        $generator->create_module('assign', ['course' => $this->course->id, 'name' => 'Essay 1']);
        $generator->create_module('page', ['course' => $this->course->id, 'name' => 'Week notes']);
    }

    /**
     * Index fields of a form by name.
     *
     * @param array $form Parsed form.
     * @return array
     */
    private static function fields(array $form): array {
        $fields = [];
        foreach ($form['fields'] as $field) {
            $fields[$field['name']] ??= $field;
        }
        return $fields;
    }

    /**
     * The first form whose action contains a path.
     *
     * @param array $page Parsed page.
     * @param string $path Path fragment.
     * @return array
     */
    private static function form(array $page, string $path): array {
        foreach ($page['forms'] as $form) {
            if (strpos($form['action'], $path) !== false) {
                return $form;
            }
        }
        throw new \coding_exception("No form posting to {$path}");
    }

    /**
     * The course settings form: page chrome, every Moodle element type and their metadata.
     */
    public function test_course_settings_form(): void {
        $url = (new \moodle_url('/course/edit.php', ['id' => $this->course->id]))->out(false);
        $page = page_parser::parse(ui_page_builder::course_edit($this->course), $url);

        $this->assertStringContainsString('Edit course settings', $page['title']);
        $this->assertSame('Biology 101', $page['heading']);
        // Which tab is active depends on navigation caches shared between tests; the edge-case fixture checks it.
        $settings = array_values(array_filter($page['tabs'], fn($t) => $t['text'] === 'Settings'));
        $links = array_column($page['links'], 'url', 'id');
        $this->assertStringContainsString('/course/edit.php?id=' . $this->course->id, $links[$settings[0]['linkid']]);
        $this->assertStringContainsString('[Form F', $page['text']);

        $form = self::form($page, '/course/edit.php');
        $this->assertSame('post', $form['method']);
        $fields = self::fields($form);
        $this->assertSame(
            ['type' => 'text', 'value' => 'Biology 101', 'required' => true, 'section' => 'General'],
            array_intersect_key($fields['fullname'], array_flip(['type', 'value', 'required', 'section']))
        );
        $this->assertSame('Course full name', $fields['fullname']['label']);
        $this->assertNotEmpty($fields['fullname']['help']);
        $this->assertTrue($fields['sesskey']['hidden']);
        $this->assertSame(['0', '1'], array_column($fields['visible']['options'], 'value'));
        $this->assertSame('1', $fields['visible']['value']);

        $this->assertSame('date_time', $fields['startdate']['type']);
        $this->assertFalse($fields['startdate']['optional']);
        $this->assertSame(['day', 'month', 'year', 'hour', 'minute'], array_keys($fields['startdate']['value']));
        $this->assertTrue($fields['enddate']['optional']);

        $this->assertSame('editor', $fields['summary_editor']['type']);
        $this->assertArrayHasKey('format', $fields['summary_editor']);
        $this->assertGreaterThan(0, (int)$fields['summary_editor']['itemid']);

        $files = $fields['overviewfiles_filemanager'];
        $this->assertSame('filemanager', $files['type']);
        $this->assertSame((int)$files['value'], $files['filemanager']['draftitemid']);
        $this->assertSame(1, $files['filemanager']['maxfiles']);
        $this->assertContains('.png', $files['filemanager']['accepted_types']);

        $this->assertTrue($fields['tags[]']['multiple']);
        $this->assertTrue($fields['tags[]']['tags']);
        $buttons = array_column($form['buttons'], null, 'name');
        $this->assertTrue($buttons['saveanddisplay']['primary']);
        $this->assertTrue($buttons['cancel']['cancel']);
    }

    /**
     * Activity settings forms: editors, optional dates, grouped checkboxes with their own labels.
     */
    public function test_activity_forms(): void {
        $url = (new \moodle_url('/course/modedit.php', ['add' => 'assign', 'course' => $this->course->id]))->out(false);
        $fields = self::fields(self::form(
            page_parser::parse(ui_page_builder::mod_add($this->course, 'assign'), $url),
            '/course/modedit.php'
        ));
        $this->assertTrue($fields['name']['required']);
        $this->assertSame('editor', $fields['introeditor']['type']);
        $this->assertTrue($fields['duedate']['optional']);
        $this->assertSame('1', $fields['duedate']['value']['enabled']);
        $this->assertStringEndsWith(': File submissions', $fields['assignsubmission_file_enabled']['label']);
        $this->assertSame('0', $fields['showdescription']['uncheckedvalue']);
        $this->assertSame('modgrade', $fields['grade[modgrade_type]']['moodletype']);

        $page = page_parser::parse(ui_page_builder::mod_add($this->course, 'page'), $url);
        $fields = self::fields(self::form($page, '/course/modedit.php'));
        $this->assertSame('editor', $fields['page']['type']);
        $this->assertTrue($fields['page']['required']);
    }

    /**
     * Admin settings: advcheckboxes, text, password and multi-checkbox settings with descriptions and defaults.
     */
    public function test_admin_settings_page(): void {
        $url = (new \moodle_url('/admin/settings.php', ['section' => 'sitepolicies']))->out(false);
        $form = self::form(page_parser::parse(ui_page_builder::admin_settings('sitepolicies'), $url), '/admin/settings.php');
        $fields = self::fields($form);
        $this->assertSame('checkbox', $fields['s__allowobjectembed']['type']);
        $this->assertSame('0', $fields['s__allowobjectembed']['uncheckedvalue']);
        $this->assertSame('Allow EMBED and OBJECT tags', $fields['s__allowobjectembed']['label']);
        $this->assertStringContainsString('Default:', $fields['s__allowobjectembed']['help']);
        $this->assertSame('text', $fields['s__minpasswordlength']['type']);
        $this->assertSame('password', $fields['s__cronremotepassword']['type']);
        $this->assertSame('', $fields['s__cronremotepassword']['value']);
        $this->assertSame('Student', $fields['s__profileroles[5]']['label']);
        $this->assertSame('section', self::fields($form)['section']['name']);
    }

    /**
     * Report-like page: alerts, headings, tables, lists, images, pagination and links.
     */
    public function test_report_page_text_and_alerts(): void {
        $url = (new \moodle_url('/report/participation/index.php', ['id' => $this->course->id]))->out(false);
        $page = page_parser::parse(ui_page_builder::report($this->course), $url);

        $this->assertSame(
            [['type' => 'success', 'text' => 'Changes saved'], ['type' => 'error', 'text' => 'Something went wrong']],
            $page['alerts']
        );
        $text = $page['text'];
        $this->assertStringContainsString('## Participants', $text);
        $this->assertStringContainsString('[image: Info icon]', $text);
        $this->assertStringContainsString("| Name | Email | Last access |\n| --- | --- | --- |", $text);
        $this->assertMatchesRegularExpression('/\| \[Admin User\]\[L\d+\] \| a@example\.com \| Never \|/', $text);
        $this->assertMatchesRegularExpression('/- \[External link\]\[L\d+\]/', $text);
        $this->assertMatchesRegularExpression('/\[Page 2\]\[L\d+\]/', $text);
        $this->assertStringNotContainsString('Dismiss this notification', $text);

        $links = array_column($page['links'], null, 'text');
        $this->assertTrue($links['External link']['external']);
        $this->assertArrayNotHasKey('external', $links['Admin User']);
        $this->assertStringContainsString('/user/view.php?id=2', $links['Admin User']['url']);

        $jump = self::form($page, '/report/participation/index.php');
        $this->assertSame('get', $jump['method']);
        $this->assertSame('5', self::fields($jump)['roleid']['value']);
    }

    /**
     * Course page: sections as headings, activities as links, screen-reader text dropped.
     */
    public function test_course_page(): void {
        $url = (new \moodle_url('/course/view.php', ['id' => $this->course->id]))->out(false);
        $page = page_parser::parse(ui_page_builder::course_view($this->course), $url);
        // Section names are links from Moodle 4.4.
        $this->assertMatchesRegularExpression('/### (\[General\]\[L\d+\]|General)\n/', $page['text']);
        $this->assertMatchesRegularExpression('/- \[Essay 1\]\[L\d+\]/', $page['text']);
        $this->assertStringNotContainsString('Essay 1 Assignment', $page['text']);
        $urls = array_column($page['links'], 'url');
        $this->assertNotEmpty(preg_grep('~/mod/assign/view\.php\?id=\d+$~', $urls));
    }

    /**
     * Saved pages from Moodle 4.2 and 4.5 parse the same way on any installed version.
     */
    public function test_saved_pages(): void {
        $fixtures = __DIR__ . '/fixtures/ui/';
        $edit = page_parser::parse(
            file_get_contents($fixtures . 'course_edit_42.html'),
            'https://www.example.com/moodle/course/edit.php?id=2'
        );
        $fields = self::fields(self::form($edit, '/course/edit.php'));
        $this->assertSame('Biology 101', $fields['fullname']['value']);
        $this->assertSame('filemanager', $fields['overviewfiles_filemanager']['type']);
        $this->assertTrue(self::fields(self::form($edit, '/course/edit.php'))['enddate']['optional']);

        $view = page_parser::parse(
            file_get_contents($fixtures . 'course_view_45.html'),
            'https://www.example.com/moodle/course/view.php?id=2'
        );
        $this->assertMatchesRegularExpression('/- \[Week notes\]\[L\d+\]/', $view['text']);

        $admin = page_parser::parse(
            file_get_contents($fixtures . 'admin_sitepolicies_45.html'),
            'https://www.example.com/moodle/admin/settings.php?section=sitepolicies'
        );
        $this->assertSame('1', self::fields(self::form($admin, 'settings.php'))['s__protectusernames']['value']);
    }

    /**
     * Hand-written edge cases: hidden content, base href, nesting, form controls outside the form, errors.
     */
    public function test_edge_cases(): void {
        $page = page_parser::parse(
            file_get_contents(__DIR__ . '/fixtures/ui/edge_cases.html'),
            'https://moodle.example.org/sub/mod/forum/post.php?reply=5'
        );
        $text = $page['text'];

        $this->assertSame('Physics 2', $page['heading']);
        $this->assertSame(['PHY 2', 'News', 'Edit'], array_column($page['breadcrumb'], 'text'));
        $this->assertSame(['type' => 'warning', 'text' => 'Mind the gap'], $page['alerts'][0]);
        $this->assertContains(['type' => 'error', 'text' => 'Subject is too long', 'field' => 'subject'], $page['alerts']);
        $this->assertSame([true, false], array_column($page['tabs'], 'active'));
        foreach (
            ['Never shown', 'Hidden by CSS', 'Inline hidden', 'screen readers only', 'Back to top', 'Block text', 'Dashboard',
                'M.cfg'] as $absent
        ) {
            $this->assertStringNotContainsString($absent, $text);
        }
        $this->assertStringContainsString('Desktop only text', $text);
        $this->assertStringContainsString("- Level one\n  - Level two [Ana][", $text);
        $this->assertStringContainsString("1. First\n2. Second", $text);
        $this->assertStringContainsString('[image: Diagram of a circuit]', $text);
        $this->assertStringNotContainsString('Icon text', $text);
        $this->assertStringContainsString('| Name | Score \| points |', $text);
        $fence = str_repeat(chr(96), 3);
        $this->assertStringContainsString("{$fence}\ncode   kept\n  as is\n{$fence}", $text);
        $this->assertStringContainsString('[Form F1: Reply]', $text);
        $this->assertStringContainsString('Subject [text: subject="Re: hello"]', $text);
        $this->assertStringContainsString('[radio: size="l", checked] Large', $text);
        $this->assertStringContainsString('[text: locked="x", disabled]', $text);
        $this->assertStringNotContainsString('secret', $text);
        $this->assertStringNotContainsString('abcDEF1234', $text);
        $this->assertMatchesRegularExpression('/\[Edit\]\[L\d+\]/', $text);

        $links = array_column($page['links'], 'url', 'text');
        $this->assertSame('https://moodle.example.org/sub/mod/forum/discuss.php?d=5', $links['first discussion']);
        $this->assertSame('https://moodle.example.org/sub/course/view.php?id=7', $links['PHY 2']);
        $this->assertSame('https://moodle.example.org/sub/user/view.php?id=4', $links['Ana']);

        $this->assertCount(1, $page['forms']);
        $form = $page['forms'][0];
        $this->assertSame('https://moodle.example.org/sub/mod/forum/post.php', $form['action']);
        $this->assertSame('multipart/form-data', $form['enctype']);
        $this->assertSame(['Send', 'Save draft'], array_column($form['buttons'], 'label'));
        $fields = self::fields($form);
        $this->assertSame('Subject is too long', $fields['subject']['error']);
        $this->assertTrue($fields['subject']['required']);
        $this->assertSame('', $fields['password']['value']);
        $this->assertSame('Good / Calm', $fields['mood']['options'][1]['label']);
        $this->assertSame('a', $fields['notused']['value']);
        $this->assertSame(['Small', 'Large'], array_column($fields['size']['options'], 'label'));
        $this->assertSame('l', $fields['size']['value']);
        $this->assertTrue($fields['locked']['disabled']);
        $this->assertSame("Hello\nthere", $fields['message']['value']);
        $this->assertSame('o', $fields['outside']['value']);
        $this->assertCount(2, array_filter($form['fields'], fn($f) => $f['name'] === 'opts[]'));
    }

    /**
     * Content inside forms (bulk-action table, grader-like table) is rendered in text, controls compactly, links numbered.
     */
    public function test_form_content_in_text(): void {
        $page = page_parser::parse(
            file_get_contents(__DIR__ . '/fixtures/ui/form_content.html'),
            'https://moodle.example.org/grade/report/grader/index.php?id=7'
        );
        $text = $page['text'];

        $this->assertStringContainsString("[Form F1: Participants]\n### Participants", $text);
        $this->assertStringContainsString('Select users for a bulk action.', $text);
        $this->assertStringContainsString('- Filtered: 2 users', $text);
        $this->assertStringContainsString('[image: Status legend]', $text);
        $this->assertMatchesRegularExpression(
            '/\| Select \| Name \| Roles \|\n\| --- \| --- \| --- \|\n'
                . '\| \[checkbox: user4="1", checked\] \| \[Ana Lopez\]\[L\d+\] \| Student \|/',
            $text
        );
        $this->assertStringContainsString('With selected users... [select: formaction="Choose..."] [Go]', $text);
        $this->assertStringContainsString('[/Form F1]', $text);

        // Grader-like table: grade inputs in cells, screen-reader labels left out of text and form title.
        $this->assertStringContainsString('[Form F2: Show per page]', $text);
        $this->assertMatchesRegularExpression(
            '/\| \[Ana Lopez\]\[L\d+\] \| \[number: grade\[4\]\[31\]="80\.00"\] \| 80\.00 \|/',
            $text
        );
        $this->assertMatchesRegularExpression('/\| \[Ben Okafor\]\[L\d+\] \| \[number: grade\[5\]\[31\]\] \| - \|/', $text);
        $this->assertStringContainsString('[select: perpage="Option 20"]', $text);
        $this->assertStringNotContainsString('Option 7', $text);
        $this->assertStringNotContainsString('hunter2', $text);
        $this->assertStringNotContainsString('Ana Lopez Essay 1', $text);
        $this->assertStringContainsString('[/Form F2]', $text);
        // Per-cell action menus are left out of text and links; menus outside tables stay.
        foreach (['Single view for this user', 'User report', 'Edit grade', 'Hide', 'Cell actions'] as $menuitem) {
            $this->assertStringNotContainsString($menuitem, $text);
        }
        $this->assertMatchesRegularExpression('/\[More\]\s+\[Export\]\[L\d+\]/', $text);

        // Every [text][Ln] reference in text names a link with that text; ids follow breadcrumb then document order.
        $links = array_column($page['links'], null, 'id');
        preg_match_all('/\[([^\[\]]+)\]\[(L\d+)\]/', $text, $refs, PREG_SET_ORDER);
        $this->assertNotEmpty($refs);
        foreach ($refs as [, $label, $id]) {
            $this->assertSame($label, $links[$id]['text']);
        }
        $this->assertSame(
            ['PHY 2', 'Grades overview', 'Ana Lopez', 'Ben Okafor', 'Essay 1', 'Export'],
            array_column($page['links'], 'text')
        );
        $this->assertSame('https://moodle.example.org/user/view.php?id=4&course=7', $links['L3']['url']);
        $this->assertCount(2, $page['forms']);
        $this->assertSame('80.00', self::fields($page['forms'][1])['grade[4][31]']['value']);
    }

    /**
     * Relative URL resolution.
     */
    public function test_resolve(): void {
        $base = 'https://m.example.org:8443/moodle/mod/quiz/view.php?id=3#x';
        $cases = [
            'attempt.php?a=1' => 'https://m.example.org:8443/moodle/mod/quiz/attempt.php?a=1',
            '../../course/view.php' => 'https://m.example.org:8443/moodle/course/view.php',
            '/moodle/my/' => 'https://m.example.org:8443/moodle/my/',
            '?page=2' => 'https://m.example.org:8443/moodle/mod/quiz/view.php?page=2',
            '' => 'https://m.example.org:8443/moodle/mod/quiz/view.php?id=3',
            '//cdn.example.com/a.js' => 'https://cdn.example.com/a.js',
            'javascript:void(0)' => '',
            'HTTP://Other.example.com/x' => 'HTTP://Other.example.com/x',
            './a/../b.php' => 'https://m.example.org:8443/moodle/mod/quiz/b.php',
        ];
        foreach ($cases as $href => $expected) {
            $this->assertSame($expected, page_parser::resolve($href, $base), $href);
        }
    }

    /**
     * Data tables hidden with d-none until JavaScript sizes them (the grader report) are still read; other d-none content is not.
     */
    public function test_js_revealed_tables_are_read(): void {
        $html = '<html><body><div id="region-main"><div class="d-none">Hidden notice</div>'
            . '<div class="gradeparent"><table class="table gradereport-grader-table d-none" id="user-grades">'
            . '<tr><th>Student</th><th>Essay 1</th></tr><tr><td>Ana Lopez</td><td>80.00</td></tr></table></div>'
            . '</div></body></html>';
        $text = page_parser::parse($html, 'https://example.com/grade/report/grader/index.php?id=2')['text'];
        $this->assertStringContainsString('| Ana Lopez | 80.00 |', $text);
        $this->assertStringNotContainsString('Hidden notice', $text);
    }
}
