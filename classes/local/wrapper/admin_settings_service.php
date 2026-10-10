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

namespace webservice_mcp\local\wrapper;

use context_system;
use core_external\external_api;

/**
 * Site administration settings through the admin tree, as admin/search.php and admin/settings.php use it.
 *
 * Access follows each settings page's own required capabilities (check_access()); values are validated by each
 * setting's write_setting() and changes are recorded in the config log, exactly like saving the admin page.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_settings_service {
    /** Maximum settings returned by a search or get. */
    private const MAX_RESULTS = 200;

    /**
     * Search settings like the admin search page (moodle/site:config, query of at least 2 characters).
     *
     * @param string $query Search text matched against names, labels and descriptions.
     * @param int $limit Maximum settings to return.
     * @return array
     */
    public function search_settings(string $query, int $limit = 50): array {
        $root = $this->admin_root();
        \require_capability('moodle/site:config', context_system::instance());

        $query = \core_text::strtolower(trim($query));
        if (\core_text::strlen($query) < 2) {
            throw arguments::invalid('query must be at least 2 characters.');
        }

        $results = [];
        $pages = [];
        foreach ($root->search($query) as $found) {
            $page = $found->page;
            if ($page->is_hidden() || !$page->check_access()) {
                continue;
            }
            $pages[] = ['section' => (string)$page->name, 'title' => (string)$page->visiblename];
            foreach ($found->settings as $setting) {
                $results[] = $this->export($setting, $page);
            }
        }

        $limit = max(1, min($limit, self::MAX_RESULTS));
        return [
            'query' => $query,
            'total' => count($results),
            'settings' => array_slice($results, 0, $limit),
            'pages' => $pages,
        ];
    }

    /**
     * Read settings by name ("name" for core, "plugin/name" for plugins) or every setting of one admin page.
     *
     * @param string[] $names Setting names.
     * @param string $section Admin page name (as in admin/settings.php?section=...).
     * @return array
     */
    public function get_settings(array $names, string $section = ''): array {
        $root = $this->admin_root();
        $settings = [];
        $missing = [];

        if ($section !== '') {
            $page = $root->locate($section);
            if (!$page instanceof \admin_settingpage || !$page->check_access()) {
                throw arguments::invalid("Admin settings page \"{$section}\" does not exist or you cannot access it.");
            }
            foreach ($page->settings as $setting) {
                $settings[] = $this->export($setting, $page);
            }
        }

        if ($names !== []) {
            $index = $this->accessible_settings($root);
            foreach (array_values(array_unique(array_map('strval', $names))) as $name) {
                if (isset($index[$this->full_name($name)])) {
                    [$setting, $page] = $index[$this->full_name($name)];
                    $settings[] = $this->export($setting, $page);
                } else {
                    $missing[] = $name;
                }
            }
        }

        if ($section === '' && $names === []) {
            throw arguments::invalid('Give names (e.g. ["maxbytes", "enrol_self/defaultenrol"]) or a section.');
        }

        return ['settings' => array_slice($settings, 0, self::MAX_RESULTS), 'unknown' => $missing];
    }

    /**
     * Save settings as the admin page does: admin_write_settings() validates each value with the setting's own
     * write_setting(), logs the change in the config log and runs the setting's update callbacks.
     *
     * @param array $values Setting name => new value.
     * @return array
     */
    public function set_settings(array $values): array {
        $root = $this->admin_root();
        if ($values === []) {
            throw arguments::invalid('settings must contain at least one setting name and value.');
        }

        $index = $this->accessible_settings($root);
        $formdata = [];
        $requested = [];
        $errors = [];
        foreach ($values as $name => $value) {
            $fullname = $this->full_name((string)$name);
            if (!isset($index[$fullname])) {
                $errors[(string)$name] = 'Unknown setting, or its admin page is not accessible to you.';
                continue;
            }
            if ($index[$fullname][0]->is_readonly()) {
                $errors[(string)$name] = 'This setting is forced in config.php and cannot be changed here.';
                continue;
            }
            $formdata[$fullname] = $this->form_value($value);
            $requested[$fullname] = (string)$name;
        }

        $saved = [];
        if ($formdata !== []) {
            \admin_write_settings($formdata);
            foreach ($requested as $fullname => $name) {
                if (isset($root->errors[$fullname])) {
                    $errors[$name] = trim(strip_tags((string)$root->errors[$fullname]->error));
                } else {
                    $saved[] = $name;
                }
            }
        }

        if ($saved === [] && $errors !== []) {
            throw arguments::invalid('No setting was saved. ' . implode('; ', array_map(
                static fn($name, $error): string => "{$name}: {$error}",
                array_keys($errors),
                array_values($errors)
            )));
        }

        return ['saved' => $saved, 'errors' => (object)$errors];
    }

    /**
     * Load the admin tree for the current user in the system context.
     *
     * @return \admin_root
     */
    private function admin_root(): \admin_root {
        moodle_lib::load('lib/adminlib.php');
        external_api::validate_context(context_system::instance());

        return \admin_get_root(false, true);
    }

    /**
     * Index every setting on admin pages the user can access by its full name (s_plugin_name).
     *
     * @param \part_of_admin_tree $node Tree node.
     * @return array full name => [admin_setting, admin_settingpage]
     */
    private function accessible_settings(\part_of_admin_tree $node): array {
        $index = [];
        if ($node instanceof \admin_category) {
            if ($node->check_access()) {
                foreach ($node->get_children() as $child) {
                    $index += $this->accessible_settings($child);
                }
            }
        } else if ($node instanceof \admin_settingpage && $node->check_access()) {
            foreach ($node->settings as $setting) {
                $index[$setting->get_full_name()] = [$setting, $node];
            }
        }

        return $index;
    }

    /**
     * Describe one setting.
     *
     * @param \admin_setting $setting Setting.
     * @param \part_of_admin_tree $page Page holding it.
     * @return array
     */
    private function export(\admin_setting $setting, \part_of_admin_tree $page): array {
        $secret = $setting instanceof \admin_setting_configpasswordunmask;
        $value = $setting->get_setting();
        $export = [
            'name' => $setting->plugin ? $setting->plugin . '/' . $setting->name : (string)$setting->name,
            'section' => (string)$page->name,
            'label' => trim(strip_tags((string)$setting->visiblename)),
            'description' => \core_text::substr(
                trim(preg_replace('/\s+/', ' ', strip_tags((string)$setting->description))),
                0,
                500
            ),
            'type' => preg_replace('/^admin_setting_/', '', get_class($setting)),
            // Secrets are not echoed back to the assistant; they can still be set.
            'value' => $secret && (string)$value !== '' ? '********' : $value,
            'default' => $secret ? null : $setting->get_defaultsetting(),
            'forced' => $setting->is_readonly(),
        ];
        if (method_exists($setting, 'load_choices') && $setting->load_choices() && is_array($setting->choices ?? null)) {
            $export['choices'] = array_map(static fn($label): string => trim(strip_tags((string)$label)), $setting->choices);
        }

        return $export;
    }

    /**
     * Convert "plugin/name" or "name" into the admin form field name s_plugin_name.
     *
     * @param string $name Setting name.
     * @return string
     */
    private function full_name(string $name): string {
        [$plugin, $setting] = str_contains($name, '/') ? explode('/', $name, 2) : ['', $name];
        return 's_' . $plugin . '_' . $setting;
    }

    /**
     * Convert a JSON value into what the admin form would submit.
     *
     * @param mixed $value Value.
     * @return mixed
     */
    private function form_value(mixed $value): mixed {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return array_map(fn($item) => $this->form_value($item), $value);
        }

        return $value === null ? '' : (string)$value;
    }
}
