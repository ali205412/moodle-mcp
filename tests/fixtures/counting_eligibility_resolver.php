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
 * Fixture eligibility resolver that counts visibility computations.
 *
 * @package     webservice_mcp
 * @copyright   2025 MohammadReza PourMohammad
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use context;
use stdClass;
use webservice_mcp\local\discovery\eligibility_resolver;

/**
 * Counts filter_visible() calls so tests can prove cache hits.
 *
 * @package     webservice_mcp
 */
final class counting_eligibility_resolver extends eligibility_resolver {
    /** @var int Number of filter_visible() calls. */
    public int $calls = 0;

    /**
     * Count and delegate.
     *
     * @param array $entries Entries.
     * @param context $restrictedcontext Restricted context.
     * @param stdClass|null $user User.
     * @param array $options Options.
     * @return array
     */
    public function filter_visible(array $entries, context $restrictedcontext, ?stdClass $user, array $options = []): array {
        $this->calls++;
        return parent::filter_visible($entries, $restrictedcontext, $user, $options);
    }
}
