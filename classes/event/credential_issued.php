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

namespace webservice_mcp\event;

use stdClass;

/**
 * An MCP connector credential was issued.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class credential_issued extends \core\event\base {
    /**
     * Build the event from a credential record.
     *
     * @param stdClass $credential Credential record.
     * @return self
     */
    public static function create_from_credential(stdClass $credential): self {
        $actor = (int)$credential->usermodified;
        return self::create([
            'context' => \context_system::instance(),
            'objectid' => (int)$credential->id,
            'relateduserid' => (int)$credential->userid,
            'userid' => $actor > 0 ? $actor : (int)$credential->userid,
            'other' => [
                'label' => (string)$credential->name,
                'tokentype' => (int)$credential->tokentype,
                'oauthclientid' => (string)($credential->oauthclientid ?? ''),
            ],
        ]);
    }

    /**
     * Init.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'webservice_mcp_credential';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:credentialissued', 'webservice_mcp');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' issued MCP connector credential '{$this->objectid}' "
            . "(type {$this->other['tokentype']}) for the user with id '{$this->relateduserid}'.";
    }

    /**
     * Object id mapping for backup/restore (not restored).
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'webservice_mcp_credential', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Other field mapping (nothing to map).
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
