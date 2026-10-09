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

use webservice_mcp\local\auth\preapproval_service;

/**
 * Bulk admin form: issue keys, pre-approve, or remove pre-approvals for selected users.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_keys_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        global $DB;

        $mform = $this->_form;
        $component = 'webservice_mcp';

        $mform->addElement('select', 'action', get_string('adminkeys:action', $component), [
            'issue' => get_string('adminkeys:action_issue', $component),
            'preapprove' => get_string('adminkeys:action_preapprove', $component),
            'unpreapprove' => get_string('adminkeys:action_unpreapprove', $component),
        ]);

        $mform->addElement('header', 'selection', get_string('adminkeys:selection', $component));
        $mform->addElement('textarea', 'identifiers', get_string('adminkeys:identifiers', $component), ['rows' => 6, 'cols' => 60]);
        $mform->setType('identifiers', PARAM_RAW);
        $mform->addHelpButton('identifiers', 'adminkeys:identifiers', $component);

        $mform->addElement('textarea', 'idnumbers', get_string('adminkeys:idnumbers', $component), ['rows' => 3, 'cols' => 60]);
        $mform->setType('idnumbers', PARAM_RAW);
        $mform->addElement('filepicker', 'usersfile', get_string('adminkeys:usersfile', $component), null, [
            'accepted_types' => ['.csv', '.txt'],
            'maxbytes' => 5 * 1024 * 1024,
        ]);
        $mform->addHelpButton('usersfile', 'adminkeys:usersfile', $component);
        if (!empty($this->_customdata['bulkcount'])) {
            $mform->addElement(
                'advcheckbox',
                'usebulkselection',
                get_string('adminkeys:usebulkselection', $component, $this->_customdata['bulkcount'])
            );
            $mform->setDefault('usebulkselection', 1);
        }

        $cohorts = [0 => get_string('none')] + $DB->get_records_menu('cohort', null, 'name', 'id, name');
        $mform->addElement('select', 'cohortid', get_string('cohort', 'cohort'), $cohorts);
        $mform->addElement('text', 'courseid', get_string('adminkeys:courseid', $component));
        $mform->setType('courseid', PARAM_INT);
        $roles = [0 => get_string('adminkeys:anyrole', $component)]
            + role_fix_names(get_all_roles(), null, ROLENAME_ORIGINAL, true);
        $mform->addElement('select', 'roleid', get_string('role'), $roles);
        $mform->addElement('advcheckbox', 'allmcpusers', get_string('adminkeys:allmcpusers', $component));

        $mform->addElement('header', 'grant', get_string('adminkeys:grant', $component));
        $mform->addElement('text', 'label', get_string('adminkeys:label', $component), ['size' => 40]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addElement('select', 'scope', get_string('adminkeys:scope', $component), [
            'read' => get_string('oauth:scope_read', $component),
            'write' => get_string('oauth:scope_write', $component),
        ]);
        $mform->addElement('text', 'expiresdays', get_string('adminkeys:expiresdays', $component));
        $mform->setType('expiresdays', PARAM_INT);
        $mform->setDefault('expiresdays', 365);
        $mform->addElement('text', 'contextid', get_string('adminkeys:contextid', $component));
        $mform->setType('contextid', PARAM_INT);
        $mform->setDefault('contextid', 0);
        $mform->addElement('textarea', 'redirecthosts', get_string('adminkeys:redirecthosts', $component), ['rows' => 5]);
        $mform->setType('redirecthosts', PARAM_RAW_TRIMMED);
        $mform->setDefault('redirecthosts', preapproval_service::DEFAULT_HOSTS);
        $mform->hideIf('redirecthosts', 'action', 'neq', 'preapprove');
        $mform->addElement('advcheckbox', 'failonskip', get_string('adminkeys:failonskip', $component));

        $this->add_action_buttons(false, get_string('adminkeys:run', $component));
    }
}
