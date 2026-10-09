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

namespace webservice_mcp\local\mcp;

/**
 * MCP Server Card (io.modelcontextprotocol/server-card): public, static connection metadata.
 *
 * Served at <server.php>/server-card. Contains no user or session data.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server_card {
    /** Card media type. */
    public const MEDIATYPE = 'application/mcp-server-card+json';

    /**
     * Plugin release (semver) for serverInfo and the card.
     *
     * @return string
     */
    public static function release(): string {
        $info = \core_plugin_manager::instance()->get_plugin_info('webservice_mcp');
        return (string)($info->release ?? '') ?: (string)get_config('webservice_mcp', 'version');
    }

    /**
     * Build the card.
     *
     * @return array
     */
    public static function build(): array {
        global $CFG, $SITE;
        $host = strtolower((string)parse_url($CFG->wwwroot, PHP_URL_HOST));
        $namespace = implode('.', array_reverse(explode('.', $host))) ?: 'local.moodle';
        $info = (new dispatcher(new call_context(call_context::ERA_MODERN, dispatcher::MODERN_VERSIONS[0])))->server_info();

        $card = [
            '$schema' => 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json',
            'name' => $namespace . '/moodle',
            'version' => self::release(),
            'title' => format_string($SITE->fullname ?? 'Moodle'),
            'description' => $info['description'],
            'websiteUrl' => $CFG->wwwroot,
            'remotes' => [[
                'type' => 'streamable-http',
                'url' => (new \moodle_url('/webservice/mcp/server.php'))->out(false),
                'supportedProtocolVersions' => dispatcher::supported_versions(),
            ]],
        ];
        if (!empty($info['icons'])) {
            $card['icons'] = $info['icons'];
        }
        return $card;
    }
}
