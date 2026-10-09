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
 * Skills over MCP (io.modelcontextprotocol/skills): Agent Skills shipped in the plugin's skills/ directory.
 *
 * Manifests are computed from the exact bytes served so hosts can verify size and digest.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skills {
    /** Extension identifier. */
    public const EXTENSION = 'io.modelcontextprotocol/skills';

    /** URI scheme. */
    private const SCHEME = 'skill://';

    /**
     * Root directory holding one subdirectory per skill.
     *
     * @return string
     */
    private static function root(): string {
        global $CFG;
        return $CFG->dirroot . '/webservice/mcp/skills';
    }

    /**
     * skills/list.
     *
     * @return array
     */
    public static function list(): array {
        $skills = [];
        foreach (glob(self::root() . '/*/SKILL.md') ?: [] as $path) {
            $skills[] = self::entry(basename(dirname($path)));
        }
        return ['skills' => $skills, 'ttlMs' => 300000, 'cacheScope' => 'public'];
    }

    /**
     * skills/get.
     *
     * @param string $uri SKILL.md URI.
     * @return array
     */
    public static function get(string $uri): array {
        $name = self::skill_name($uri);
        if ($name === null || self::path($uri) === null || self::path($uri) !== realpath(self::root() . "/{$name}/SKILL.md")) {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Unknown skill: ' . $uri);
        }
        return ['skill' => self::entry($name), 'ttlMs' => 300000, 'cacheScope' => 'public'];
    }

    /**
     * resources/read for skill:// URIs.
     *
     * @param string $uri URI.
     * @return array|null Result, or null when not a skill URI.
     */
    public static function read(string $uri): ?array {
        if (!str_starts_with($uri, self::SCHEME)) {
            return null;
        }
        $path = self::path($uri);
        if ($path === null || !is_file($path)) {
            return null;
        }
        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => str_ends_with($path, '.md') ? 'text/markdown' : 'text/plain',
                'text' => file_get_contents($path),
            ]],
            'ttlMs' => 300000,
            'cacheScope' => 'public',
        ];
    }

    /**
     * resources/directory/read for skill:// directories.
     *
     * @param string $uri Directory URI (no trailing slash).
     * @return array
     */
    public static function read_directory(string $uri): array {
        $path = self::path($uri);
        if ($path === null || !is_dir($path)) {
            throw new protocol_exception(protocol_exception::INVALID_PARAMS, 'Unknown directory: ' . $uri);
        }
        $children = [];
        foreach (scandir($path) ?: [] as $child) {
            if ($child[0] === '.') {
                continue;
            }
            $isdir = is_dir("{$path}/{$child}");
            $children[] = [
                'uri' => rtrim($uri, '/') . '/' . $child,
                'name' => $child,
                'mimeType' => $isdir ? 'inode/directory' : (str_ends_with($child, '.md') ? 'text/markdown' : 'text/plain'),
            ];
        }
        return ['resources' => $children, 'ttlMs' => 300000, 'cacheScope' => 'public'];
    }

    /**
     * Build a skill entry: frontmatter plus complete manifest.
     *
     * @param string $name Skill directory name.
     * @return array
     */
    private static function entry(string $name): array {
        $dir = self::root() . '/' . $name;
        $manifest = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            $manifest[$relative] = [
                'uri' => self::SCHEME . $name . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative),
                'digest' => 'sha256:' . hash_file('sha256', $file->getPathname()),
                'size' => $file->getSize(),
            ];
        }
        ksort($manifest);
        return [
            'uri' => self::SCHEME . $name . '/SKILL.md',
            'frontmatter' => self::frontmatter((string)file_get_contents($dir . '/SKILL.md')),
            'resources' => array_values($manifest),
        ];
    }

    /**
     * Parse the simple key: value YAML frontmatter used by the bundled skills.
     *
     * @param string $markdown SKILL.md content.
     * @return array
     */
    private static function frontmatter(string $markdown): array {
        if (!preg_match('/\A---\n(.*?)\n---\n/s', $markdown, $matches)) {
            return [];
        }
        $fields = [];
        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $pair)) {
                $fields[$pair[1]] = trim($pair[2], " \"'");
            }
        }
        return $fields;
    }

    /**
     * Skill name from a URI.
     *
     * @param string $uri URI.
     * @return string|null
     */
    private static function skill_name(string $uri): ?string {
        if (!preg_match('#^skill://([a-z0-9-]+)(/|$)#', $uri, $matches)) {
            return null;
        }
        return $matches[1];
    }

    /**
     * Resolve a skill:// URI to a file path inside the skills root, refusing traversal.
     *
     * @param string $uri URI.
     * @return string|null
     */
    private static function path(string $uri): ?string {
        if (self::skill_name($uri) === null) {
            return null;
        }
        $relative = substr($uri, strlen(self::SCHEME));
        if (str_contains($relative, '..') || str_contains($relative, '\\')) {
            return null;
        }
        $path = self::root() . '/' . rtrim($relative, '/');
        $real = realpath($path);
        return ($real !== false && str_starts_with($real, realpath(self::root()) . '/')) ? $real : null;
    }
}
