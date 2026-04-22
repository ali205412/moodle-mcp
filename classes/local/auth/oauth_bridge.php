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

use core\oauth2\issuer;
use moodle_exception;
use moodle_url;

/**
 * Bridge for Moodle-managed OAuth login handoff into connector bootstrap.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth_bridge {
    /**
     * Build the Moodle OAuth login URL for a connector bootstrap return path.
     *
     * @param int $issuerid OAuth issuer id.
     * @param moodle_url $returnurl Return URL back into launch.php.
     * @return moodle_url
     */
    public function build_login_url(int $issuerid, moodle_url $returnurl): moodle_url {
        if (!is_enabled_auth('oauth2') || !\auth_oauth2\api::is_enabled()) {
            throw new moodle_exception('notenabled', 'auth_oauth2');
        }

        $issuer = new issuer($issuerid);
        if (!$issuer->is_available_for_login()) {
            throw new moodle_exception('issuernologin', 'auth_oauth2');
        }

        return new moodle_url('/auth/oauth2/login.php', [
            'id' => $issuerid,
            'sesskey' => sesskey(),
            'wantsurl' => $returnurl,
        ]);
    }

    /**
     * Redirect into Moodle OAuth login.
     *
     * @param int $issuerid OAuth issuer id.
     * @param moodle_url $returnurl Return URL back into launch.php.
     * @return never
     */
    public function redirect_to_login(int $issuerid, moodle_url $returnurl): void {
        redirect($this->build_login_url($issuerid, $returnurl));
    }
}
