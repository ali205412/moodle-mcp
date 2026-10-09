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

namespace webservice_mcp\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Pre-register an OAuth client.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'webservice_mcp';

        $mform->addElement('text', 'name', get_string('clients:name', $component), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('textarea', 'redirecturis', get_string('clients:redirecturis', $component), ['rows' => 4, 'cols' => 60]);
        $mform->setType('redirecturis', PARAM_RAW_TRIMMED);
        $mform->addRule('redirecturis', null, 'required', null, 'client');
        $mform->addHelpButton('redirecturis', 'clients:redirecturis', $component);
        $mform->addElement('advcheckbox', 'confidential', get_string('clients:confidential', $component));
        $mform->addElement('select', 'scope', get_string('adminkeys:scope', $component), [
            'write' => get_string('oauth:scope_write', $component),
            'read' => get_string('oauth:scope_read', $component),
        ]);
        $this->add_action_buttons(false, get_string('clients:register', $component));
    }
}
