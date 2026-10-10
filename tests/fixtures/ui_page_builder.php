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

/**
 * Renders real Moodle pages (full Boost header and footer around real forms and content) inside PHPUnit.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

/**
 * Renders real Moodle pages for the UI bridge parser tests, so they run against the installed version's markup.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ui_page_builder {
    /**
     * Render a full page: header, main content, footer, with the general (HTML) renderer.
     *
     * @param string $path Page path.
     * @param array $params Page URL parameters.
     * @param \stdClass|null $course Course, or null for system context.
     * @param string $layout Page layout.
     * @param string $title Page title.
     * @param callable $main fn(): string, called after $PAGE is set up.
     * @return string HTML.
     */
    public static function page(
        string $path,
        array $params,
        ?\stdClass $course,
        string $layout,
        string $title,
        callable $main
    ): string {
        global $PAGE;

        $PAGE = new \moodle_page();
        $PAGE->set_url(new \moodle_url($path, $params));
        if ($course) {
            $PAGE->set_course($course);
        } else {
            $PAGE->set_context(\context_system::instance());
        }
        $PAGE->set_pagelayout($layout);
        $PAGE->set_title($title);
        $PAGE->set_heading($course ? $course->fullname : 'Moodle site');
        $content = $main();
        $renderer = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL);
        return $renderer->header() . $content . $renderer->footer();
    }

    /**
     * The course settings form page (course/edit.php).
     *
     * @param \stdClass $course Course.
     * @return string HTML.
     */
    public static function course_edit(\stdClass $course): string {
        global $CFG;
        require_once($CFG->dirroot . '/course/edit_form.php');
        return self::page(
            '/course/edit.php',
            ['id' => $course->id],
            $course,
            'admin',
            'Edit course settings',
            function () use ($course, $CFG) {
                $context = \context_course::instance($course->id);
                $editoroptions = ['maxfiles' => EDITOR_UNLIMITED_FILES, 'maxbytes' => $CFG->maxbytes, 'trusttext' => false,
                    'noclean' => true, 'context' => $context, 'subdirs' => 0];
                $data = file_prepare_standard_editor(clone $course, 'summary', $editoroptions, $context, 'course', 'summary', 0);
                $form = new \course_edit_form(null, ['course' => $data, 'category' => \core_course_category::get($course->category),
                    'editoroptions' => $editoroptions, 'returnto' => 0,
                    'returnurl' => new \moodle_url('/course/view.php', ['id' => $course->id])]);
                return $form->render();
            }
        );
    }

    /**
     * The "add an activity" form (course/modedit.php?add=...).
     *
     * @param \stdClass $course Course.
     * @param string $modname Module name.
     * @return string HTML.
     */
    public static function mod_add(\stdClass $course, string $modname): string {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . "/mod/{$modname}/mod_form.php");
        return self::page(
            '/course/modedit.php',
            ['add' => $modname, 'course' => $course->id, 'section' => 0],
            $course,
            'admin',
            'Adding a new ' . $modname,
            function () use ($course, $modname) {
                [, , $cw, $cm, $data] = prepare_new_moduleinfo_data($course, $modname, 0);
                $class = 'mod_' . $modname . '_mod_form';
                $form = new $class($data, $cw->section, $cm, $course);
                $form->set_data($data);
                return $form->render();
            }
        );
    }

    /**
     * An admin settings page (admin/settings.php), wrapped exactly as core does.
     *
     * @param string $section Settings section.
     * @return string HTML.
     */
    public static function admin_settings(string $section): string {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return self::page(
            '/admin/settings.php',
            ['section' => $section],
            null,
            'admin',
            'Settings',
            function () use ($section) {
                global $PAGE;
                $page = admin_get_root()->locate($section, true);
                return $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL)->render_from_template('core_admin/settings', [
                    'actionurl' => $PAGE->url->out(false), 'params' => [['name' => 'section', 'value' => $section]],
                    'sesskey' => sesskey(), 'return' => '', 'title' => null, 'settings' => $page->output_html(),
                    'showsave' => true]);
            }
        );
    }

    /**
     * A report-like page: notifications, heading, text with an image, a list, a table, paging and a jump menu.
     *
     * @param \stdClass $course Course.
     * @return string HTML.
     */
    public static function report(\stdClass $course): string {
        return self::page(
            '/report/participation/index.php',
            ['id' => $course->id],
            $course,
            'report',
            'Participation report',
            function () use ($course) {
                global $PAGE;
                $r = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL);
                $table = new \html_table();
                $table->head = ['Name', 'Email', 'Last access'];
                $table->data = [
                    [\html_writer::link(new \moodle_url('/user/view.php', ['id' => 2]), 'Admin User'), 'a@example.com', 'Never'],
                    ['Ana', 'ana@example.com', '2 days'],
                ];
                $url = new \moodle_url('/report/participation/index.php', ['id' => $course->id]);
                return $r->notification('Changes saved', 'success') . $r->notification('Something went wrong', 'error')
                    . $r->heading('Participants', 2)
                    . \html_writer::tag('p', 'Some <b>intro</b> text with an '
                        . \html_writer::img((new \moodle_url('/pix/i/info.png'))->out(false), 'Info icon') . ' image.')
                    . \html_writer::alist(['First item', \html_writer::link('https://example.org/x', 'External link')])
                    . \html_writer::table($table)
                    . $r->paging_bar(100, 0, 20, $url)
                    . $r->single_select($url, 'roleid', [5 => 'Student', 3 => 'Teacher'], 5);
            }
        );
    }

    /**
     * The course page (course/view.php content from the course format).
     *
     * @param \stdClass $course Course.
     * @return string HTML.
     */
    public static function course_view(\stdClass $course): string {
        return self::page(
            '/course/view.php',
            ['id' => $course->id],
            $course,
            'course',
            $course->fullname,
            function () use ($course) {
                global $PAGE;
                $format = course_get_format($course);
                $class = $format->get_output_classname('content');
                return $PAGE->get_renderer('format_' . $course->format, null, RENDERER_TARGET_GENERAL)
                    ->render(new $class($format));
            }
        );
    }
}
