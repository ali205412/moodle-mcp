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

use context_course;
use core_external\external_api;
use stdClass;

/**
 * Course enrolment methods, as enrol/instances.php and enrol/editinstance.php manage them.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_instance_service {
    /**
     * Add an enrolment method with the plugin's own edit form (can_add_instance() and edit_instance_validation()).
     *
     * @param int $courseid Course id.
     * @param string $plugin Enrolment plugin, e.g. "self", "guest", "cohort".
     * @param array $settings Edit form field name => value (e.g. name, password, enrolperiod, customint1...).
     * @return array
     */
    public function add_instance(int $courseid, string $plugin, array $settings): array {
        [$course, $context, $enrol] = $this->course_plugin($courseid, $plugin);
        \require_capability('moodle/course:enrolconfig', $context);
        if (!$enrol->can_add_instance($course->id)) {
            throw arguments::invalid("You cannot add a {$plugin} enrolment method to this course (missing "
                . "enrol/{$plugin}:config, or the method allows only one instance and it already exists).");
        }

        $instance = (object)$enrol->get_instance_defaults();
        $instance->id = null;
        $instance->courseid = $course->id;
        $instance->status = ENROL_INSTANCE_ENABLED;
        $data = $this->submit_form($course, $context, $enrol, $plugin, $instance, $settings);

        $instanceid = (int)$enrol->add_instance($course, (array)$data);
        if ($instanceid <= 0) {
            throw arguments::invalid("The {$plugin} plugin did not create an enrolment method.");
        }

        return $this->export($this->instance((int)$course->id, $instanceid), $enrol);
    }

    /**
     * Change an enrolment method's settings through the plugin's edit form.
     *
     * @param int $instanceid Enrolment instance id.
     * @param array $settings Edit form field name => value.
     * @return array
     */
    public function update_instance(int $instanceid, array $settings): array {
        // Like enrol/editinstance.php, editing needs enrol/<plugin>:config (checked in submit_form), not enrolconfig.
        [$course, $context, $enrol, $instance] = $this->load_instance($instanceid, false);
        if ($settings === []) {
            throw arguments::invalid('settings must contain at least one field to change.');
        }

        $data = $this->submit_form($course, $context, $enrol, $instance->enrol, $instance, $settings);
        $reset = isset($data->status) && (int)$instance->status !== (int)$data->status;
        $enrol->update_instance($instance, $data);
        if ($reset) {
            $context->mark_dirty();
        }

        return $this->export($this->instance((int)$course->id, $instanceid), $enrol);
    }

    /**
     * Delete an enrolment method and its enrolments, as enrol/instances.php does.
     *
     * @param int $instanceid Enrolment instance id.
     * @param bool $confirmselfaccess Confirms deleting the method your own access to the course depends on.
     * @return array
     */
    public function delete_instance(int $instanceid, bool $confirmselfaccess = false): array {
        global $DB;

        [, , $enrol, $instance] = $this->load_instance($instanceid);
        if (!$enrol->can_delete_instance($instance)) {
            throw arguments::invalid("You cannot delete this {$instance->enrol} enrolment method (enrol/{$instance->enrol}:config "
                . 'is required, and some methods cannot be deleted).');
        }
        $this->require_self_access_confirmation($instance, $confirmselfaccess, 'deleting');

        $users = $DB->count_records('user_enrolments', ['enrolid' => $instance->id]);
        $enrol->delete_instance($instance);

        return ['instanceid' => $instanceid, 'deleted' => true, 'unenrolledusers' => $users];
    }

    /**
     * Enable or disable an enrolment method, as the eye icon on enrol/instances.php does.
     *
     * @param int $instanceid Enrolment instance id.
     * @param bool $enabled Whether the method should be enabled.
     * @param bool $confirmselfaccess Confirms disabling the method your own access depends on.
     * @return array
     */
    public function set_status(int $instanceid, bool $enabled, bool $confirmselfaccess = false): array {
        [$course, , $enrol, $instance] = $this->load_instance($instanceid);
        if (!$enrol->can_hide_show_instance($instance)) {
            throw arguments::invalid("You cannot enable or disable this {$instance->enrol} enrolment method.");
        }
        if (!$enabled) {
            $this->require_self_access_confirmation($instance, $confirmselfaccess, 'disabling');
        }

        $enrol->update_status($instance, $enabled ? ENROL_INSTANCE_ENABLED : ENROL_INSTANCE_DISABLED);

        return $this->export($this->instance((int)$course->id, $instanceid), $enrol);
    }

    /**
     * Submit the enrolment method edit form (enrol/editinstance_form.php) with the requested settings.
     *
     * @param stdClass $course Course.
     * @param context_course $context Course context.
     * @param \enrol_plugin $enrol Plugin.
     * @param string $type Plugin name.
     * @param stdClass $instance Instance or defaults.
     * @param array $settings Requested settings.
     * @return stdClass
     */
    private function submit_form(
        stdClass $course,
        context_course $context,
        \enrol_plugin $enrol,
        string $type,
        stdClass $instance,
        array $settings
    ): stdClass {
        if (!$enrol->use_standard_editing_ui()) {
            throw arguments::invalid("The {$type} enrolment method has its own settings page; use the page tools for it.");
        }
        \require_capability('enrol/' . $type . ':config', $context);
        foreach (['id', 'courseid', 'type', 'enrol', 'returnurl'] as $reserved) {
            if (array_key_exists($reserved, $settings)) {
                throw arguments::invalid("The setting \"{$reserved}\" identifies the method and cannot be changed.");
            }
        }

        moodle_lib::load('enrol/editinstance_form.php');
        $returnurl = (new \moodle_url('/enrol/instances.php', ['id' => $course->id]))->out(false);
        $mform = new \enrol_instance_edit_form(null, [$instance, $enrol, $context, $type, $returnurl]);

        $unknown = array_diff(array_map('strval', array_keys($settings)), form_submission::field_names($mform));
        if ($unknown !== []) {
            throw arguments::invalid("Unknown settings for {$type}: " . implode(', ', $unknown) . '. Available: '
                . implode(', ', array_diff(form_submission::field_names($mform), ['id', 'courseid', 'type', 'returnurl'])) . '.');
        }

        [$data, $errors] = form_submission::submit($mform, array_map(
            static fn($value) => is_bool($value) ? (int)$value : $value,
            $settings
        ));
        if ($data === null) {
            throw form_submission::rejected('enrolment settings', $errors);
        }

        return $data;
    }

    /**
     * Refuse to remove the user's own access without explicit confirmation (the page asks a second time).
     *
     * @param stdClass $instance Instance.
     * @param bool $confirmed Whether the caller confirmed.
     * @param string $action Action name for the message.
     * @return void
     */
    private function require_self_access_confirmation(stdClass $instance, bool $confirmed, string $action): void {
        if (!$confirmed && \enrol_accessing_via_instance($instance)) {
            throw arguments::invalid("You are enrolled through this method, so {$action} it may remove your own access to the "
                . 'course. Repeat with confirmselfaccess=true to go ahead.');
        }
    }

    /**
     * Load the course, context and enabled enrolment plugin.
     *
     * @param int $courseid Course id.
     * @param string $plugin Plugin name.
     * @return array [course, context, enrol_plugin]
     */
    private function course_plugin(int $courseid, string $plugin): array {
        moodle_lib::load('lib/enrollib.php');

        $course = \get_course($courseid);
        $context = context_course::instance($course->id);
        external_api::validate_context($context);

        $enrol = \core_component::is_valid_plugin_name('enrol', $plugin) ? \enrol_get_plugin($plugin) : null;
        if (!$enrol) {
            throw arguments::invalid("Unknown enrolment plugin \"{$plugin}\".");
        }
        if (!\enrol_is_enabled($plugin)) {
            throw arguments::invalid("The {$plugin} enrolment plugin is disabled on this site.");
        }

        return [$course, $context, $enrol];
    }

    /**
     * Load an instance; actions on enrol/instances.php need moodle/course:enrolreview and enrolconfig.
     *
     * @param int $instanceid Instance id.
     * @param bool $instancespage Whether to apply the enrol/instances.php capability checks.
     * @return array [course, context, enrol_plugin, instance]
     */
    private function load_instance(int $instanceid, bool $instancespage = true): array {
        global $DB;

        $instance = $DB->get_record('enrol', ['id' => $instanceid]);
        if (!$instance) {
            throw arguments::invalid("Enrolment method {$instanceid} does not exist.");
        }
        [$course, $context, $enrol] = $this->course_plugin((int)$instance->courseid, (string)$instance->enrol);
        if ($instancespage) {
            \require_capability('moodle/course:enrolreview', $context);
            \require_capability('moodle/course:enrolconfig', $context);
        }

        return [$course, $context, $enrol, $instance];
    }

    /**
     * Load an instance record.
     *
     * @param int $courseid Course id.
     * @param int $instanceid Instance id.
     * @return stdClass
     */
    private function instance(int $courseid, int $instanceid): stdClass {
        global $DB;
        return $DB->get_record('enrol', ['id' => $instanceid, 'courseid' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Describe an instance.
     *
     * @param stdClass $instance Instance.
     * @param \enrol_plugin $enrol Plugin.
     * @return array
     */
    private function export(stdClass $instance, \enrol_plugin $enrol): array {
        return [
            'instanceid' => (int)$instance->id,
            'courseid' => (int)$instance->courseid,
            'plugin' => (string)$instance->enrol,
            'name' => $enrol->get_instance_name($instance),
            'enabled' => (int)$instance->status === ENROL_INSTANCE_ENABLED,
            'roleid' => (int)$instance->roleid,
        ];
    }
}
