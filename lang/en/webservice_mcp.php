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
 * English language strings for the MCP web service plugin.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['err_empty_request'] = 'Request body is empty';
$string['err_invalid_json'] = 'Invalid JSON';
$string['err_invalid_jsonrpc'] = 'Invalid JSON-RPC version';
$string['err_missing_method'] = 'Missing method';
$string['err_missing_tool_name'] = 'Missing tool name';
$string['launch:denied'] = 'You do not have permission to bootstrap Moodle MCP access.';
$string['launch:heading'] = 'Moodle MCP access';
$string['launch:success'] = 'A connector credential was issued for the current user.';
$string['manageconnectors'] = 'Manage Moodle MCP connector credentials';
$string['mcp:use'] = 'Use MCP web service';
$string['mcp:manageconnectors'] = 'Manage MCP connector credentials';
$string['pluginname'] = 'Model Context Protocol';
$string['privacy:metadata'] = 'The MCP web service plugin does not store any personal data. It provides a protocol for accessing existing Moodle web service functions.';
$string['settings:allowedorigins'] = 'Allowed transport origins';
$string['settings:allowedorigins_desc'] = 'Comma-separated or line-separated Origin values allowed to use browser-facing MCP transport endpoints. Empty means same-site and no-Origin requests only.';
$string['settings:allowdurablegrants'] = 'Allow durable connector grants';
$string['settings:allowdurablegrants_desc'] = 'If enabled, the plugin may issue explicit long-lived remote connector grants after bootstrap. Bootstrap credentials remain short-lived by default.';
$string['settings:companionenabled'] = 'Enable companion seam';
$string['settings:companionenabled_desc'] = 'Expose the Phase 1 companion-service seam configuration. The Moodle plugin remains authoritative for auth, discovery, and execution.';
$string['settings:connectorserviceidentifier'] = 'Connector service identifier';
$string['settings:connectorserviceidentifier_desc'] = 'Stable service identifier stored on plugin-managed connector credentials.';
$string['settings:enablelegacysse'] = 'Enable legacy SSE compatibility';
$string['settings:enablelegacysse_desc'] = 'Expose a dedicated legacy SSE compatibility endpoint in addition to the primary HTTP transport.';
$string['settings:replayttl'] = 'Replay buffer TTL';
$string['settings:replayttl_desc'] = 'How long replay/event state is retained for MCP transport sessions.';
$string['settings:showhighrisktools'] = 'Show high-risk tools in discovery';
$string['settings:showhighrisktools_desc'] = 'If disabled, discovery hides tools classified as high or critical risk even when the current user could otherwise access them.';
$string['settings:transportsessionttl'] = 'Transport session TTL';
$string['settings:transportsessionttl_desc'] = 'How long MCP transport session state is retained before expiry.';
