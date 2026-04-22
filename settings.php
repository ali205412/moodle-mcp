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
 * Plugin settings for the Moodle MCP web service plugin.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig && $settings instanceof admin_settingpage && $ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'webservice_mcp/connectorserviceidentifier',
        get_string('settings:connectorserviceidentifier', 'webservice_mcp'),
        get_string('settings:connectorserviceidentifier_desc', 'webservice_mcp'),
        'webservice_mcp_connector',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/allowdurablegrants',
        get_string('settings:allowdurablegrants', 'webservice_mcp'),
        get_string('settings:allowdurablegrants_desc', 'webservice_mcp'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/companionenabled',
        get_string('settings:companionenabled', 'webservice_mcp'),
        get_string('settings:companionenabled_desc', 'webservice_mcp'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'webservice_mcp/allowedorigins',
        get_string('settings:allowedorigins', 'webservice_mcp'),
        get_string('settings:allowedorigins_desc', 'webservice_mcp'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/enablelegacysse',
        get_string('settings:enablelegacysse', 'webservice_mcp'),
        get_string('settings:enablelegacysse_desc', 'webservice_mcp'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/oauthenabled',
        get_string('settings:oauthenabled', 'webservice_mcp'),
        get_string('settings:oauthenabled_desc', 'webservice_mcp'),
        1
    ));

    $settings->add(new admin_setting_configduration(
        'webservice_mcp/transportsessionttl',
        get_string('settings:transportsessionttl', 'webservice_mcp'),
        get_string('settings:transportsessionttl_desc', 'webservice_mcp'),
        3600
    ));

    $settings->add(new admin_setting_configduration(
        'webservice_mcp/replayttl',
        get_string('settings:replayttl', 'webservice_mcp'),
        get_string('settings:replayttl_desc', 'webservice_mcp'),
        3600
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/showhighrisktools',
        get_string('settings:showhighrisktools', 'webservice_mcp'),
        get_string('settings:showhighrisktools_desc', 'webservice_mcp'),
        1
    ));
}
