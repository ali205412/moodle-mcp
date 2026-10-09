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

    $settings->add(new admin_setting_configtextarea(
        'webservice_mcp/allowedredirecthosts',
        get_string('settings:allowedredirecthosts', 'webservice_mcp'),
        get_string('settings:allowedredirecthosts_desc', 'webservice_mcp'),
        \webservice_mcp\local\oauth\client_registry::DEFAULT_REDIRECT_HOSTS,
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/allowsiteadmins',
        get_string('settings:allowsiteadmins', 'webservice_mcp'),
        get_string('settings:allowsiteadmins_desc', 'webservice_mcp'),
        1
    ));

    $settings->add(new admin_setting_configduration(
        'webservice_mcp/oauthfamilylifetime',
        get_string('settings:oauthfamilylifetime', 'webservice_mcp'),
        get_string('settings:oauthfamilylifetime_desc', 'webservice_mcp'),
        90 * DAYSECS,
        DAYSECS
    ));

    $settings->add(new admin_setting_configtext(
        'webservice_mcp/refreshgraceseconds',
        get_string('settings:refreshgraceseconds', 'webservice_mcp'),
        get_string('settings:refreshgraceseconds_desc', 'webservice_mcp'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'webservice_mcp/secretrotationoverlap',
        get_string('settings:secretrotationoverlap', 'webservice_mcp'),
        get_string('settings:secretrotationoverlap_desc', 'webservice_mcp'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'webservice_mcp/credentialretentiondays',
        get_string('settings:credentialretentiondays', 'webservice_mcp'),
        get_string('settings:credentialretentiondays_desc', 'webservice_mcp'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'webservice_mcp/dcrclientretentiondays',
        get_string('settings:dcrclientretentiondays', 'webservice_mcp'),
        get_string('settings:dcrclientretentiondays_desc', 'webservice_mcp'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'webservice_mcp/adminlistperpage',
        get_string('settings:adminlistperpage', 'webservice_mcp'),
        get_string('settings:adminlistperpage_desc', 'webservice_mcp'),
        50,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'webservice_mcp/auditretentiondays',
        get_string('settings:auditretentiondays', 'webservice_mcp'),
        get_string('settings:auditretentiondays_desc', 'webservice_mcp'),
        90,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtextarea(
        'webservice_mcp/preapprovalclientids',
        get_string('settings:preapprovalclientids', 'webservice_mcp'),
        get_string('settings:preapprovalclientids_desc', 'webservice_mcp'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'webservice_mcp/adminkeymaxdays',
        get_string('settings:adminkeymaxdays', 'webservice_mcp'),
        get_string('settings:adminkeymaxdays_desc', 'webservice_mcp'),
        365,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/notifyonadminkey',
        get_string('settings:notifyonadminkey', 'webservice_mcp'),
        get_string('settings:notifyonadminkey_desc', 'webservice_mcp'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/emaenabled',
        get_string('settings:emaenabled', 'webservice_mcp'),
        get_string('settings:emaenabled_desc', 'webservice_mcp'),
        0
    ));

    $settings->add(new admin_setting_configtextarea(
        'webservice_mcp/ematrustedissuers',
        get_string('settings:ematrustedissuers', 'webservice_mcp'),
        get_string('settings:ematrustedissuers_desc', 'webservice_mcp'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/emarequirejti',
        get_string('settings:emarequirejti', 'webservice_mcp'),
        get_string('settings:emarequirejti_desc', 'webservice_mcp'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'webservice_mcp/emausermatchfield',
        get_string('settings:emausermatchfield', 'webservice_mcp'),
        get_string('settings:emausermatchfield_desc', 'webservice_mcp'),
        'email',
        [
            'email' => get_string('email'),
            'username' => get_string('username'),
            'idnumber' => get_string('idnumber'),
        ]
    ));

    $settings->add(new admin_setting_description(
        'webservice_mcp/connectionslink',
        get_string('connections:heading', 'webservice_mcp'),
        html_writer::link(
            new moodle_url('/webservice/mcp/connections.php', ['all' => 1]),
            get_string('settings:connectionslink', 'webservice_mcp')
        )
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
        'webservice_mcp/confirmdestructive',
        get_string('confirmdestructive', 'webservice_mcp'),
        get_string('confirmdestructive_desc', 'webservice_mcp'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'webservice_mcp/exposenativetools',
        get_string('exposenativetools', 'webservice_mcp'),
        get_string('exposenativetools_desc', 'webservice_mcp'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'webservice_mcp/filesheading',
        get_string('settings:filesheading', 'webservice_mcp'),
        get_string('settings:filesheading_desc', 'webservice_mcp')
    ));

    foreach (['downloadticketttl' => 900, 'uploadticketttl' => 3600] as $name => $default) {
        $settings->add(new admin_setting_configduration(
            'webservice_mcp/' . $name,
            get_string('settings:' . $name, 'webservice_mcp'),
            get_string('settings:' . $name . '_desc', 'webservice_mcp'),
            $default,
            MINSECS
        ));
    }

    $filesintsettings = [
        'inlinetextmaxbytes' => 262144,
        'inlinebinarymaxbytes' => 5242880,
        'uploadinlinemaxbytes' => 15728640,
        'uploadfromurlmaxbytes' => 104857600,
        'uploadmaxbytes' => 2147483648,
        'uploadmaxpartials' => 5,
        'uploadmaxpartialbytes' => 0,
    ];
    foreach ($filesintsettings as $name => $default) {
        $settings->add(new admin_setting_configtext(
            'webservice_mcp/' . $name,
            get_string('settings:' . $name, 'webservice_mcp'),
            get_string('settings:' . $name . '_desc', 'webservice_mcp'),
            $default,
            PARAM_INT
        ));
    }
}

if ($hassiteconfig) {
    $ADMIN->add($parentnodename, new admin_externalpage(
        'webservice_mcp_clients',
        get_string('clients:heading', 'webservice_mcp'),
        new moodle_url('/webservice/mcp/admin/clients.php'),
        'webservice/mcp:manageconnectors'
    ));
    $ADMIN->add($parentnodename, new admin_externalpage(
        'webservice_mcp_keys',
        get_string('adminkeys:heading', 'webservice_mcp'),
        new moodle_url('/webservice/mcp/admin/keys.php'),
        'webservice/mcp:issueforothers'
    ));
}
