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
 * UI bridge tools: view any Moodle page, follow action links and submit forms as the signed-in user.
 *
 * @package    webservice_mcp
 * @copyright  2026 Aspire School
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace webservice_mcp\local\ui;

use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * Tool definitions and execution for the UI bridge.
 *
 * @package    webservice_mcp
 */
final class tools {
    /** @var string Placeholder shown to clients instead of the bridge session's sesskey. */
    public const SESSKEY = '{sesskey}';

    /** @var int Characters of page text returned per call. */
    private const PAGE_CHARS = 40000;

    /** @var int Links returned per page at most. */
    private const MAX_LINKS = 400;

    /** @var session_bridge|null Test seam. */
    public static ?session_bridge $bridge = null;

    /**
     * Tool definitions.
     *
     * @return array
     */
    private static function definitions(): array {
        $url = ['type' => 'string', 'maxLength' => 2048,
            'description' => 'Moodle page URL on this site, absolute or a path such as /course/view.php?id=4.'];
        return [
            'moodle_page_view' => [
                'title' => 'View a Moodle page',
                'description' => 'Open any page of this Moodle site exactly as the signed-in user sees it in the browser and '
                    . 'get its readable text, alerts, tabs, numbered links (L1, L2…) and forms (F1, F2… with every field, '
                    . 'its label, current value and options). Use it for anything the API tools do not cover: reports, '
                    . 'settings pages, plugin pages, admin pages, gradebook setup, block configuration. Follow a link by '
                    . 'viewing its url; links that change something contain {sesskey} and must go through '
                    . 'moodle_page_action; fill in and send a form with moodle_page_submit. Long pages are paged: pass '
                    . 'offset from nexttextoffset. Files and exports the page returns are saved to your draft area and '
                    . 'returned as a moodle://file uri.',
                'properties' => [
                    'url' => $url,
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Text offset for long pages.'],
                    'length' => ['type' => 'integer', 'minimum' => 1000, 'maximum' => 100000,
                        'description' => 'Characters of text to return (default 40000).'],
                ],
                'required' => ['url'],
                'mutating' => false,
            ],
            'moodle_page_action' => [
                'title' => 'Follow a Moodle action link',
                'description' => 'Follow a link that changes something (it contains {sesskey}), for example hide, delete, '
                    . 'move, enrol or approve links from moodle_page_view, as the signed-in user. Moodle may answer '
                    . 'with a confirmation page: submit its form with moodle_page_submit. Returns the resulting page.',
                'properties' => ['url' => $url],
                'required' => ['url'],
                'mutating' => true,
            ],
            'moodle_page_submit' => [
                'title' => 'Submit a Moodle form',
                'description' => 'Fill in and submit a form on a Moodle page as the signed-in user, exactly like pressing '
                    . 'its button in the browser: Moodle runs its own validation and permission checks. Give the page '
                    . 'url, the form (F1… from moodle_page_view, or its id/name), and only the fields to change, by '
                    . 'field name (selects and radios also accept the option label). Unchanged fields keep their current '
                    . 'values. Editors take HTML; file pickers take a draftitemid prepared with the file tools. Returns '
                    . 'the resulting page; check alerts and forms for validation errors.',
                'properties' => [
                    'url' => $url,
                    'form' => ['type' => 'string', 'maxLength' => 255,
                        'description' => 'Form to submit: F1, F2… from moodle_page_view, or the form id or name.'],
                    'fields' => ['type' => 'object', 'additionalProperties' => true,
                        'description' => 'Field name => value. Arrays for multi-selects and grouped fields.'],
                    'button' => ['type' => 'string', 'maxLength' => 255,
                        'description' => 'Submit button name, value or label when the form has several (e.g. "Save and display").'],
                ],
                'required' => ['url', 'form'],
                'mutating' => true,
            ],
        ];
    }

    /**
     * Whether the bridge is offered to this caller.
     *
     * @param call_context $ctx Request context.
     * @return bool
     */
    public static function available(call_context $ctx): bool {
        if (!$ctx->connector || $ctx->user === null || isguestuser($ctx->user) || !get_config('webservice_mcp', 'uibridge')) {
            return false;
        }
        if ($ctx->restrictedcontext !== null && $ctx->restrictedcontext->contextlevel != CONTEXT_SYSTEM) {
            return false;
        }
        return has_capability('webservice/mcp:uibridge', \context_system::instance(), $ctx->user);
    }

    /**
     * Wire-shape tool definitions.
     *
     * @param call_context $ctx Request context.
     * @return array
     */
    public static function describe(call_context $ctx): array {
        if (!self::available($ctx)) {
            return [];
        }
        $tools = [];
        foreach (self::definitions() as $name => $def) {
            $tools[] = [
                'name' => $name,
                'title' => $def['title'],
                'description' => $def['description'],
                'inputSchema' => ['type' => 'object', 'properties' => $def['properties'], 'required' => $def['required'],
                    'additionalProperties' => false],
                'annotations' => [
                    'title' => $def['title'],
                    'readOnlyHint' => !$def['mutating'],
                    'destructiveHint' => $def['mutating'],
                    'idempotentHint' => !$def['mutating'],
                    'openWorldHint' => false,
                ],
                '_meta' => ['anthropic/alwaysLoad' => true, 'anthropic/maxResultSizeChars' => 200000],
            ];
        }
        return $tools;
    }

    /**
     * Whether a tool name belongs to the bridge.
     *
     * @param string $name Tool name.
     * @return bool
     */
    public static function handles(string $name): bool {
        return array_key_exists($name, self::definitions());
    }

    /**
     * Whether a tool changes state.
     *
     * @param string $name Tool name.
     * @return bool
     */
    public static function is_mutating(string $name): bool {
        return (bool)(self::definitions()[$name]['mutating'] ?? true);
    }

    /**
     * Run a bridge tool and return a CallToolResult; failures are thrown for the dispatcher to convert.
     *
     * @param string $name Tool name.
     * @param array $args Arguments.
     * @param call_context $ctx Request context.
     * @return array
     */
    public static function execute(string $name, array $args, call_context $ctx): array {
        if (!self::available($ctx)) {
            throw new transfer_exception(403, 'uibridgeunavailable', 'Browsing Moodle pages is not enabled for this '
                . 'connection (needs a full-site connector credential, the uibridge setting and webservice/mcp:uibridge).');
        }
        $url = self::url_arg($args);
        $bridge = self::$bridge ?? new session_bridge();

        if ($name === 'moodle_page_view') {
            if (self::has_sesskey($url)) {
                throw new transfer_exception(400, 'uibridgeneedsaction', 'That link changes something (it carries '
                    . '{sesskey}); use moodle_page_action to follow it.');
            }
            return self::result($ctx, $bridge->fetch($ctx, 'GET', $url), $args);
        }

        $sesskey = $bridge->sesskey($ctx);
        if ($name === 'moodle_page_action') {
            if (!self::has_sesskey($url)) {
                throw new transfer_exception(400, 'uibridgenotaction', 'That link does not change anything; view it with '
                    . 'moodle_page_view.');
            }
            return self::result($ctx, $bridge->fetch($ctx, 'GET', self::unmask($url, $sesskey)), []);
        }

        // Submit: always re-read the page so the form, its defaults and the sesskey are current.
        $page = $bridge->fetch($ctx, 'GET', self::unmask($url, $sesskey));
        if (!self::is_html($page)) {
            throw new transfer_exception(400, 'uibridgenoform', 'That URL does not return a page with forms.');
        }
        $parsed = page_parser::parse($page['body'], $page['url']);
        $form = self::find_form($parsed['forms'] ?? [], trim((string)($args['form'] ?? '')));
        $values = self::unmask_values((array)($args['fields'] ?? []), $page['sesskey'] ?? $sesskey);
        $submission = form_submitter::build($form, $values, isset($args['button']) ? (string)$args['button'] : null);
        $response = $bridge->fetch(
            $ctx,
            strtoupper($submission['method']) === 'GET' ? 'GET' : 'POST',
            $submission['action'],
            $submission['fields'],
            !empty($submission['multipart'])
        );
        $submitted = ['submitted' => ['form' => $form['id'], 'action' => $submission['action']]];
        return self::result($ctx, $response, [], $submitted);
    }

    /**
     * Turn a bridge response into a CallToolResult.
     *
     * @param call_context $ctx Context.
     * @param array $response Fetch response.
     * @param array $args Paging arguments.
     * @param array $extra Extra structured fields.
     * @return array
     */
    private static function result(
        call_context $ctx,
        array $response,
        array $args,
        array $extra = []
    ): array {
        $sesskey = (string)($response['sesskey'] ?? '');
        if (!self::is_html($response)) {
            $saved = download_saver::save($response, $ctx);
            $payload = $extra + ['type' => 'file', 'url' => self::mask((string)$response['url'], $sesskey), 'file' => $saved,
                'next' => 'Read it with file_read or get a link with file_get_download_url.'];
            return self::wrap($payload, "The page returned a file: {$saved['filename']} ({$saved['uri']}). "
                . 'Read it with file_read.');
        }

        $page = page_parser::parse($response['body'], $response['url']);
        $page = self::mask($page, $sesskey);
        $text = (string)($page['text'] ?? '');
        $offset = max(0, (int)($args['offset'] ?? 0));
        $length = min(max(1000, (int)($args['length'] ?? self::PAGE_CHARS)), 100000);
        $total = \core_text::strlen($text);
        $page['text'] = \core_text::substr($text, $offset, $length);
        $page['textoffset'] = $offset;
        $page['texttotal'] = $total;
        if ($offset + $length < $total) {
            $page['nexttextoffset'] = $offset + $length;
        }
        if (count($page['links'] ?? []) > self::MAX_LINKS) {
            $page['linkstruncated'] = count($page['links']);
            $page['links'] = array_slice($page['links'], 0, self::MAX_LINKS);
        }
        $page['status'] = (int)$response['status'];
        $payload = $extra + $page;
        return self::wrap($payload, self::render($payload));
    }

    /**
     * Compact text rendering for clients that show text only.
     *
     * @param array $page Parsed, masked page.
     * @return string
     */
    private static function render(array $page): string {
        $out = ['# ' . ($page['title'] ?? 'Moodle page'), 'URL: ' . ($page['url'] ?? '')];
        if (!empty($page['submitted'])) {
            $out[] = "Submitted form {$page['submitted']['form']}.";
        }
        foreach ($page['alerts'] ?? [] as $alert) {
            $out[] = '[' . strtoupper((string)($alert['type'] ?? 'info')) . '] ' . ($alert['text'] ?? '');
        }
        if (!empty($page['breadcrumb'])) {
            $out[] = 'Path: ' . implode(' > ', array_map(fn($b) => is_array($b) ? ($b['text'] ?? '') : (string)$b,
                $page['breadcrumb']));
        }
        if (!empty($page['tabs'])) {
            $out[] = 'Tabs: ' . implode(' | ', array_map(fn($t) => ($t['text'] ?? '') . (isset($t['id']) ? " [{$t['id']}]" : ''),
                $page['tabs']));
        }
        $out[] = '';
        $out[] = (string)($page['text'] ?? '');
        if (isset($page['nexttextoffset'])) {
            $out[] = "… more text: call again with offset {$page['nexttextoffset']} (of {$page['texttotal']}).";
        }
        if (!empty($page['links'])) {
            $out[] = '';
            $out[] = 'Links:';
            foreach ($page['links'] as $link) {
                $out[] = "{$link['id']} {$link['text']} -> {$link['url']}";
            }
        }
        foreach ($page['forms'] ?? [] as $form) {
            $out[] = '';
            $out[] = "Form {$form['id']}" . (!empty($form['name']) ? " ({$form['name']})" : '')
                . " {$form['method']} {$form['action']}";
            foreach ($form['fields'] ?? [] as $field) {
                if (!empty($field['hidden'])) {
                    continue;
                }
                $line = "- {$field['name']} ({$field['type']})";
                if (!empty($field['label'])) {
                    $line .= " \"{$field['label']}\"";
                }
                if (!empty($field['required'])) {
                    $line .= ' required';
                }
                if (isset($field['value']) && $field['value'] !== '' && $field['value'] !== []) {
                    $line .= ' = ' . (is_array($field['value']) ? implode(', ', $field['value']) : $field['value']);
                }
                if (!empty($field['options'])) {
                    $line .= ' options: ' . implode(', ', array_map(fn($o) => ($o['label'] ?? '') . '=' . ($o['value'] ?? ''),
                        array_slice($field['options'], 0, 30))) . (count($field['options']) > 30 ? ', …' : '');
                }
                $out[] = $line;
            }
            if (!empty($form['buttons'])) {
                $out[] = '  buttons: ' . implode(', ', array_map(fn($b) => $b['label'] ?? $b['value'] ?? '', $form['buttons']));
            }
        }
        return implode("\n", $out);
    }

    /**
     * Build a CallToolResult.
     *
     * @param array $payload Structured content.
     * @param string $text Text content.
     * @return array
     */
    private static function wrap(array $payload, string $text): array {
        // The page path (never the query) goes into the audit row of this tool call.
        $path = (string)parse_url((string)($payload['url'] ?? ''), PHP_URL_PATH);
        return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $payload,
            '_meta' => ['org.moodle/auditdetail' => \core_text::substr($path, 0, 255)]];
    }

    /**
     * Find a parsed form by F-id, id/name attribute or 1-based index.
     *
     * @param array $forms Parsed forms.
     * @param string $ref Reference.
     * @return array
     */
    private static function find_form(array $forms, string $ref): array {
        foreach ($forms as $index => $form) {
            if (
                $ref !== '' && ($ref === ($form['id'] ?? null) || $ref === ($form['formid'] ?? null)
                    || $ref === ($form['name'] ?? null) || $ref === (string)($index + 1))
            ) {
                return $form;
            }
        }
        $available = implode(', ', array_map(fn($f) => ($f['id'] ?? '?') . (!empty($f['name']) ? " ({$f['name']})" : ''), $forms));
        throw new transfer_exception(400, 'uibridgeformnotfound', "No form \"{$ref}\" on that page. "
            . ($available !== '' ? "Forms: {$available}." : 'The page has no forms.'));
    }

    /**
     * Validate and resolve the url argument.
     *
     * @param array $args Arguments.
     * @return string
     */
    private static function url_arg(array $args): string {
        $url = trim((string)($args['url'] ?? ''));
        if ($url === '') {
            throw new transfer_exception(400, 'invalidparameter', 'url is required, e.g. /course/view.php?id=4.');
        }
        return $url;
    }

    /**
     * Whether a URL carries a sesskey (masked or real).
     *
     * @param string $url URL.
     * @return bool
     */
    private static function has_sesskey(string $url): bool {
        return str_contains($url, self::SESSKEY) || str_contains(rawurldecode($url), self::SESSKEY)
            || (bool)preg_match('/[?&]sesskey=/', $url);
    }

    /**
     * Whether a response is an HTML page.
     *
     * @param array $response Fetch response.
     * @return bool
     */
    private static function is_html(array $response): bool {
        return !download_saver::is_download($response);
    }

    /**
     * Replace the real sesskey with the placeholder, recursively.
     *
     * @param mixed $value Value.
     * @param string $sesskey Real sesskey.
     * @return mixed
     */
    private static function mask(mixed $value, string $sesskey): mixed {
        if ($sesskey === '') {
            return $value;
        }
        if (is_array($value)) {
            return array_map(fn($v) => self::mask($v, $sesskey), $value);
        }
        return is_string($value) ? str_replace($sesskey, self::SESSKEY, $value) : $value;
    }

    /**
     * Put the real sesskey back into a URL.
     *
     * @param string $url URL possibly containing the placeholder (raw or encoded).
     * @param string $sesskey Real sesskey.
     * @return string
     */
    private static function unmask(string $url, string $sesskey): string {
        return str_replace([self::SESSKEY, rawurlencode(self::SESSKEY)], $sesskey, $url);
    }

    /**
     * Put the real sesskey back into submitted values.
     *
     * @param array $values Values.
     * @param string $sesskey Real sesskey.
     * @return array
     */
    private static function unmask_values(array $values, string $sesskey): array {
        return array_map(fn($v) => is_array($v) ? self::unmask_values($v, $sesskey)
            : (is_string($v) ? str_replace(self::SESSKEY, $sesskey, $v) : $v), $values);
    }
}
