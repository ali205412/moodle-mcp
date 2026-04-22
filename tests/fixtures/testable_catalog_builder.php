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
 * Fixture catalog builder used by PHPUnit coverage tests.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace webservice_mcp;

use webservice_mcp\local\catalog\catalog_builder;
use webservice_mcp\local\catalog\wrapper_registry;

/**
 * Testable catalog builder with injectable component inventory.
 *
 * @package     webservice_mcp
 */
final class testable_catalog_builder extends catalog_builder {
    /** @var array */
    private array $componentlistoverride;

    /**
     * Constructor.
     *
     * @param wrapper_registry|null $wrapperregistry Optional wrapper registry override.
     * @param array $componentlistoverride Override installed component inventory.
     */
    public function __construct(?wrapper_registry $wrapperregistry = null, array $componentlistoverride = []) {
        parent::__construct($wrapperregistry);
        $this->componentlistoverride = $componentlistoverride;
    }

    /**
     * Override component inventory for coverage tests.
     *
     * @return array
     */
    protected function component_list(): array {
        if ($this->componentlistoverride !== []) {
            return $this->componentlistoverride;
        }

        return parent::component_list();
    }
}
