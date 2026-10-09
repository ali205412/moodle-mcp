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

use context_system;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use webservice_mcp\local\auth\credential_manager;
use webservice_mcp\privacy\provider;

/**
 * Privacy provider tests.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\privacy\provider
 */
final class privacy_provider_test extends provider_testcase {
    /**
     * Give a user a credential and a memory.
     *
     * @param \stdClass $user User.
     * @return void
     */
    private function create_data(\stdClass $user): void {
        global $DB;

        (new credential_manager())->issue_durable_grant(
            (object)['shortname' => 'webservice_mcp_connector'],
            $user->id,
            context_system::instance(),
            ['name' => 'Claude connector']
        );
        $DB->insert_record('webservice_mcp_memory', (object)[
            'userid' => $user->id,
            'content' => 'Remember this',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Test contexts, users, export, and deletion.
     */
    public function test_export_and_delete(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_data($user);
        $this->create_data($other);
        $system = context_system::instance();

        $contextids = provider::get_contexts_for_userid((int)$user->id)->get_contextids();
        $this->assertSame([(int)$system->id], array_map('intval', $contextids));

        $userlist = new userlist($system, 'webservice_mcp');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([(int)$user->id, (int)$other->id], array_map('intval', $userlist->get_userids()));

        $this->export_context_data_for_user((int)$user->id, $system, 'webservice_mcp');
        $writer = writer::with_context($system);
        $this->assertTrue($writer->has_any_data());
        $pluginname = get_string('pluginname', 'webservice_mcp');
        $credentials = $writer->get_data([$pluginname, get_string('privacy:path:credentials', 'webservice_mcp')]);
        $this->assertSame('Claude connector', $credentials->credentials[0]['name']);

        provider::delete_data_for_user(new approved_contextlist($user, 'webservice_mcp', [$system->id]));
        $this->assertFalse($DB->record_exists('webservice_mcp_credential', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('webservice_mcp_memory', ['userid' => $user->id]));
        $this->assertTrue($DB->record_exists('webservice_mcp_memory', ['userid' => $other->id]));

        provider::delete_data_for_users(new approved_userlist($system, 'webservice_mcp', [$other->id]));
        $this->assertFalse($DB->record_exists('webservice_mcp_memory', ['userid' => $other->id]));
    }
}
