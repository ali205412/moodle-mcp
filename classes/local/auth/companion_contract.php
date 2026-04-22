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

namespace webservice_mcp\local\auth;

/**
 * Edge-only companion seam for Phase 1.
 *
 * This contract may describe transport-facing bootstrap exchange or remote
 * connector handoff metadata, but it is explicitly NOT the authority for
 * Moodle permissions, tool discovery, or tool execution.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface companion_contract {
    /**
     * Build transport-facing metadata for a connector credential exchange.
     *
     * @param array $payload Plugin-generated bootstrap payload.
     * @return array
     */
    public function build_exchange_payload(array $payload): array;

    /**
     * Build transport-facing metadata for a known connector session.
     *
     * @param string $credentialtoken Connector token.
     * @return array
     */
    public function describe_session(string $credentialtoken): array;
}
