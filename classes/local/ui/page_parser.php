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

namespace webservice_mcp\local\ui;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Turns a Moodle (Boost) HTML page into structured data an AI client can act on.
 *
 * Returns the title, heading, breadcrumb, alerts, tabs, the main region as markdown-like text with numbered link
 * references (forms included: their content is rendered between [Form Fn] and [/Form Fn], controls as
 * [type: name="value"]), every link, and every form with its fields (see form_parser). Pure DOM work: no Moodle state is
 * read, so it parses pages from any Moodle 4.x site.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_parser {
    /** Elements whose content is never shown. */
    private const SKIP_TAGS = ['script', 'style', 'noscript', 'template', 'head', 'svg', 'iframe', 'object', 'select',
        'textarea', 'input', 'option', 'datalist', 'canvas', 'audio', 'video', 'map'];

    /** Form controls: shown compactly inside forms, skipped elsewhere. */
    private const CONTROL_TAGS = ['input', 'select', 'textarea'];

    /** Longest control value shown in the text; the full value is in forms[]. */
    private const MAX_VALUE_CHARS = 80;

    /** List indentation marker (kept through tidy(), then turned into two spaces). */
    private const INDENT = "\x01";

    /** Classes that hide an element (screen-reader copies, JS-hidden content). */
    private const HIDDEN_CLASSES = ['sr-only', 'visually-hidden', 'accesshide', 'hidden', 'hide', 'd-none', 'collapse-hidden',
        'dropdown-toggle-icon'];

    /** Block-level elements: rendered on their own lines. */
    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'main', 'fieldset',
        'figure', 'figcaption', 'details', 'summary', 'dl', 'dt', 'dd', 'address', 'legend', 'blockquote', 'center', 'form',
        'tbody', 'thead', 'caption'];

    /** @var DOMXPath */
    private DOMXPath $xpath;

    /** @var string Base URL for relative links. */
    private string $base;

    /** @var string Origin (scheme://host[:port]) of the page. */
    private string $origin;

    /** @var array Links by id. */
    private array $links = [];

    /** @var array Link id by url + text. */
    private array $linkids = [];

    /** @var bool Render links as plain text (breadcrumb, tab and heading labels). */
    private bool $plainlinks = false;

    /** @var \SplObjectStorage Form element => form id (F1...). */
    private \SplObjectStorage $formids;

    /** @var int Depth of rendered forms around the current node (controls are shown only inside forms). */
    private int $formdepth = 0;

    /** @var int Depth of table cells around the current node (dropdown menus are left out inside cells). */
    private int $celldepth = 0;

    /**
     * Parse a page.
     *
     * @param string $html Page HTML.
     * @param string $url Page URL (after redirects); relative links and form actions resolve against it.
     * @return array title, url, heading, breadcrumb, alerts, tabs, text, links, forms.
     */
    public static function parse(string $html, string $url): array {
        return (new self())->run($html, $url);
    }

    /**
     * Resolve a possibly relative URL against a base URL.
     *
     * @param string $href Reference.
     * @param string $base Absolute base URL.
     * @return string Absolute URL ('' for javascript:, data: and similar).
     */
    public static function resolve(string $href, string $base): string {
        $href = trim($href);
        if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $href, $m)) {
            return in_array(strtolower($m[1]), ['http', 'https', 'mailto'], true) ? $href : '';
        }
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return $href;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strpos($href, '//') === 0) {
            return $b['scheme'] . ':' . $href;
        }
        $basepath = $b['path'] ?? '/';
        $basequery = isset($b['query']) ? '?' . $b['query'] : '';
        if ($href === '') {
            return $origin . $basepath . $basequery;
        }
        if ($href[0] === '#') {
            return $origin . $basepath . $basequery . $href;
        }
        if ($href[0] === '?') {
            return $origin . $basepath . $href;
        }
        $path = $href[0] === '/' ? $href : substr($basepath, 0, (int)strrpos($basepath, '/') + 1) . $href;
        $suffix = '';
        if (preg_match('~^([^?#]*)(.*)$~s', $path, $m)) {
            [$path, $suffix] = [$m[1], $m[2]];
        }
        $segments = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '..') {
                if (count($segments) > 1) {
                    array_pop($segments);
                }
            } else if ($segment !== '.' && ($segment !== '' || $i === 0)) {
                $segments[] = $segment;
            }
        }
        $normalised = implode('/', $segments);
        if (substr($path, -1) === '/' || preg_match('~/\.\.?$~', $path)) {
            $normalised .= '/';
        }
        return $origin . '/' . ltrim($normalised, '/') . $suffix;
    }

    /**
     * Parse.
     *
     * @param string $html Page HTML.
     * @param string $url Page URL.
     * @return array
     */
    private function run(string $html, string $url): array {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($doc);
        $base = $this->first('//base[@href]');
        $this->base = $base instanceof DOMElement ? self::resolve($base->getAttribute('href'), $url) : $url;
        $parts = parse_url($this->base) ?: [];
        $this->origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->formids = new \SplObjectStorage();

        $forms = [];
        $index = 0;
        foreach ($this->xpath->query('//form') as $form) {
            if ($this->in_chrome_only($form)) {
                continue;
            }
            $id = 'F' . (++$index);
            $this->formids[$form] = $id;
            $forms[] = ['id' => $id] + (new form_parser($this->xpath, $this->base))->parse($form);
        }

        $main = $this->first("//*[@id='region-main']") ?? $this->first("//*[@role='main']")
            ?? $this->first('//body') ?? $doc->documentElement;
        $result = [
            'title' => $this->clean((string)($this->first('//title')->textContent ?? '')),
            'url' => $url,
            'heading' => $this->heading($main),
            'breadcrumb' => $this->breadcrumb(),
            'alerts' => $this->alerts(),
            'tabs' => $this->tabs(),
            'text' => $main ? self::tidy($this->render($main)) : '',
        ];
        $result['links'] = array_values($this->links);
        $result['forms'] = $forms;
        return $result;
    }

    /**
     * The page heading (h1 in the page header, else the first h1).
     *
     * @param DOMNode|null $main Main region.
     * @return string
     */
    private function heading(?DOMNode $main): string {
        $h1 = $this->first("//*[@id='page-header']//h1") ?? $this->first('//*[' . self::cls('page-header-headings') . ']//h1')
            ?? ($main ? $this->first('.//h1', $main) : null) ?? $this->first('//h1');
        return $h1 ? $this->clean($this->inline($h1)) : '';
    }

    /**
     * Breadcrumb items with link ids.
     *
     * @return array
     */
    private function breadcrumb(): array {
        $items = [];
        foreach ($this->xpath->query('//ol[' . self::cls('breadcrumb') . ']/li') as $li) {
            $a = $this->first('.//a[@href]', $li);
            $text = $this->clean($this->inline($li));
            if ($text === '') {
                continue;
            }
            $item = ['text' => $text];
            if ($a instanceof DOMElement && ($id = $this->link($a->getAttribute('href'), $text))) {
                $item['linkid'] = $id;
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Notifications, alerts and form validation errors.
     *
     * @return array [{type, text, field?}]
     */
    private function alerts(): array {
        $alerts = [];
        $seen = [];
        $add = function (string $type, string $text, ?string $field = null) use (&$alerts, &$seen) {
            $text = $this->clean($text);
            if ($text === '' || isset($seen[$type . $text . $field])) {
                return;
            }
            $seen[$type . $text . $field] = true;
            $alerts[] = array_filter(['type' => $type, 'text' => $text, 'field' => $field], fn($v) => $v !== null);
        };
        foreach ($this->xpath->query('//*[' . self::cls('alert') . ' or @role="alert"]') as $alert) {
            if (!$alert instanceof DOMElement || $this->is_hidden($alert) || $this->inside('template', $alert)) {
                continue;
            }
            $class = ' ' . preg_replace('/\s+/', ' ', $alert->getAttribute('class')) . ' ';
            $type = preg_match('/ alert-(danger|error) /', $class) ? 'error'
                : (strpos($class, ' alert-warning ') !== false ? 'warning'
                : (strpos($class, ' alert-success ') !== false ? 'success' : 'info'));
            $add($type, $this->render($alert, true));
        }
        foreach (
            $this->xpath->query('//*[' . self::cls('invalid-feedback') . ' or ' . self::cls('error')
                . ' or starts-with(@id, "id_error_") or starts-with(@id, "fgroup_id_error_")]') as $error
        ) {
            if (!$error instanceof DOMElement || $error->tagName === 'form' || $error->tagName === 'input') {
                continue;
            }
            $field = preg_match('/^(?:fgroup_)?id_error_(.+)$/', $error->getAttribute('id'), $m) ? $m[1] : null;
            $add('error', $error->textContent, $field);
        }
        return $alerts;
    }

    /**
     * Secondary navigation and tab links.
     *
     * @return array [{text, linkid, active}]
     */
    private function tabs(): array {
        $tabs = [];
        $seen = [];
        $query = '//*[' . self::cls('secondary-navigation') . ']//a[@href] | //ul[' . self::cls('nav-tabs') . ']//a[@href]'
            . ' | //*[' . self::cls('tertiary-navigation') . ']//a[@href]';
        foreach ($this->xpath->query($query) as $a) {
            if (!$a instanceof DOMElement) {
                continue;
            }
            $text = $this->clean($this->inline($a));
            $id = $text !== '' ? $this->link($a->getAttribute('href'), $text) : null;
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $class = ' ' . preg_replace('/\s+/', ' ', $a->getAttribute('class')) . ' ';
            $tabs[] = ['text' => $text, 'linkid' => $id,
                'active' => strpos($class, ' active ') !== false || $a->getAttribute('aria-current') === 'true'];
        }
        return $tabs;
    }

    /**
     * Render an element as markdown-like text.
     *
     * @param DOMNode $node Node.
     * @param bool $ignorebuttons Leave out buttons (alert close buttons).
     * @return string
     */
    private function render(DOMNode $node, bool $ignorebuttons = false): string {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $out .= preg_replace('/\s+/u', ' ', $child->nodeValue);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($this->formdepth > 0 && in_array($tag, self::CONTROL_TAGS, true)) {
                $out .= $this->hidden_by_markup($child) ? '' : $this->render_control($child, $ignorebuttons);
                continue;
            }
            if ($this->is_hidden($child)) {
                continue;
            }
            if (
                $this->celldepth > 0 && ($child->getAttribute('role') === 'menu'
                    || in_array('dropdown-menu', preg_split('/\s+/', $child->getAttribute('class')), true))
            ) {
                // Per-cell action menus (grader report, quiz overrides) would flood text and links; keep the trigger only.
                continue;
            }
            if ($tag === 'form' && isset($this->formids[$child])) {
                // Forms often hold a page's main content (grader report, bulk edits): render it, marked with the form id.
                $id = $this->formids[$child];
                $title = $this->form_title($child);
                $this->formdepth++;
                try {
                    $inner = trim($this->render($child, $ignorebuttons));
                } finally {
                    $this->formdepth--;
                }
                $out .= "\n\n[Form {$id}" . ($title !== '' ? ": {$title}" : '') . "]\n"
                    . ($inner !== '' ? $inner . "\n[/Form {$id}]\n" : '') . "\n";
                continue;
            }
            if ($tag === 'button' || ($tag === 'a' && strpos(' ' . $child->getAttribute('class') . ' ', ' btn-close ') !== false)) {
                if (!$ignorebuttons && $tag === 'button' && ($label = $this->clean($this->inline($child))) !== '') {
                    $out .= " [{$label}] ";
                }
                continue;
            }
            switch ($tag) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $text = $this->clean($this->render($child, $ignorebuttons));
                    $out .= $text === '' ? '' : "\n\n" . str_repeat('#', (int)$tag[1]) . ' ' . $text . "\n\n";
                    break;
                case 'br':
                    $out .= "\n";
                    break;
                case 'hr':
                    $out .= "\n\n---\n\n";
                    break;
                case 'ul':
                case 'ol':
                    // Nested lists render flush; the enclosing item indents them.
                    $out .= "\n" . $this->render_list($child, $tag === 'ol') . "\n";
                    break;
                case 'table':
                    $out .= "\n\n" . $this->render_table($child) . "\n\n";
                    break;
                case 'a':
                    $out .= $this->render_link($child);
                    break;
                case 'img':
                    $alt = $this->clean($child->getAttribute('alt'));
                    $isicon = preg_match('/(^| )(icon|iconsmall|iconlarge|activityicon)( |$)/', $child->getAttribute('class'));
                    $out .= ($alt !== '' && !$isicon) ? " [image: {$alt}] " : '';
                    break;
                case 'pre':
                    // Fenced: tidy() leaves the lines between fences untouched.
                    $fence = self::fence();
                    $out .= "\n{$fence}\n" . rtrim(str_replace($fence, "'''", $child->textContent)) . "\n{$fence}\n";
                    break;
                case 'li':
                    $out .= "\n- " . trim($this->render($child, $ignorebuttons)) . "\n";
                    break;
                default:
                    $inner = $this->render($child, $ignorebuttons);
                    $out .= in_array($tag, self::BLOCK_TAGS, true) || preg_match('/^h\d$/', $tag) ? "\n" . $inner . "\n" : $inner;
            }
        }
        return $out;
    }

    /**
     * Render a list.
     *
     * @param DOMElement $list ul or ol.
     * @param bool $ordered Numbered.
     * @return string
     */
    private function render_list(DOMElement $list, bool $ordered): string {
        $lines = [];
        $n = 0;
        foreach ($list->childNodes as $li) {
            if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li' || $this->is_hidden($li)) {
                continue;
            }
            $text = trim(self::tidy($this->render($li), false));
            if ($text === '') {
                continue;
            }
            if ($text[0] === '#') {
                // A list used as layout (course sections): keep its headings as headings.
                $lines[] = "\n" . $text . "\n";
                continue;
            }
            $bullet = $ordered ? (++$n) . '. ' : '- ';
            // Indent continuation lines with a marker that tidy() turns into spaces after trimming stray whitespace.
            $lines[] = $bullet . str_replace("\n", "\n" . self::INDENT, $text);
        }
        return implode("\n", $lines);
    }

    /**
     * Render a table as | a | b | rows, with a separator after a header row.
     *
     * @param DOMElement $table Table.
     * @return string
     */
    private function render_table(DOMElement $table): string {
        $rows = [];
        $headerrow = null;
        foreach ($this->xpath->query('./tr | ./thead/tr | ./tbody/tr | ./tfoot/tr', $table) as $tr) {
            if (!$tr instanceof DOMElement || $this->is_hidden($tr)) {
                continue;
            }
            $cells = [];
            $allth = true;
            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }
                $allth = $allth && strtolower($cell->tagName) === 'th';
                $this->celldepth++;
                try {
                    $text = $this->clean(str_replace("\n", ' ', $this->render($cell)));
                } finally {
                    $this->celldepth--;
                }
                $cells[] = str_replace('|', '\\|', $text);
            }
            if ($cells && trim(implode('', $cells)) !== '') {
                if ($headerrow === null && ($allth || strtolower($tr->parentNode->nodeName) === 'thead')) {
                    $headerrow = count($rows);
                }
                $rows[] = '| ' . implode(' | ', $cells) . ' |';
            }
        }
        $caption = $this->first('./caption', $table);
        $out = $caption ? $this->clean($caption->textContent) . "\n" : '';
        foreach ($rows as $i => $row) {
            $out .= $row . "\n";
            if ($i === $headerrow) {
                $out .= '|' . str_repeat(' --- |', substr_count($row, ' | ') + 1) . "\n";
            }
        }
        return rtrim($out);
    }

    /**
     * Render a link as [text][Ln].
     *
     * @param DOMElement $a Anchor.
     * @return string
     */
    private function render_link(DOMElement $a): string {
        $href = trim($a->getAttribute('href'));
        if ($href === '' || $href[0] === '#' || stripos($href, 'javascript:') === 0) {
            // In-page anchors are JavaScript controls (collapse toggles, menus), not navigation.
            return '';
        }
        $text = $this->clean($this->render($a, true));
        if ($text === '') {
            // Icon-only and screen-reader-labelled links (pagination: aria-hidden "2" + sr-only "Page 2").
            $sronly = $this->first('.//*[' . self::cls('sr-only') . ' or ' . self::cls('visually-hidden') . ' or '
                . self::cls('accesshide') . ']', $a);
            $text = $this->clean($a->getAttribute('aria-label') ?: $a->getAttribute('title'))
                ?: ($sronly ? $this->clean($sronly->textContent) : '') ?: $this->clean($a->textContent);
        }
        if ($text === '') {
            $labelled = $this->first('.//*[@title or @aria-label or (self::img and @alt != "")]', $a);
            if ($labelled instanceof DOMElement) {
                $text = $this->clean($labelled->getAttribute('title') ?: $labelled->getAttribute('aria-label')
                    ?: $labelled->getAttribute('alt'));
            }
        }
        if ($text === '' || !$a->hasAttribute('href')) {
            return $text;
        }
        $id = $this->plainlinks ? null : $this->link($a->getAttribute('href'), $text);
        return $id ? "[{$text}][{$id}]" : $text;
    }

    /**
     * Render a form control as [type: name="value", flags]; buttons as [label]. Option lists stay in forms[] only.
     *
     * @param DOMElement $el input, select or textarea.
     * @param bool $ignorebuttons Leave out buttons.
     * @return string
     */
    private function render_control(DOMElement $el, bool $ignorebuttons): string {
        $tag = strtolower($el->tagName);
        $type = $tag === 'input' ? (strtolower($el->getAttribute('type')) ?: 'text') : $tag;
        if (in_array($type, ['submit', 'button', 'reset', 'image'], true)) {
            $label = $this->clean($el->getAttribute('value') ?: $el->getAttribute('alt') ?: $el->getAttribute('aria-label'));
            return !$ignorebuttons && $label !== '' ? " [{$label}] " : '';
        }
        $name = $el->getAttribute('name');
        if ($name === '') {
            return '';
        }
        $flags = [];
        if ($type === 'select') {
            $selected = [];
            foreach ($this->xpath->query('.//option', $el) as $option) {
                if ($option instanceof DOMElement && $option->hasAttribute('selected')) {
                    $selected[] = $option;
                }
            }
            if (!$selected && !$el->hasAttribute('multiple') && ($first = $this->first('.//option', $el))) {
                $selected[] = $first;
            }
            $value = implode(', ', array_map(fn($o) => $o->getAttribute('label') ?: $o->textContent, $selected));
        } else if ($type === 'textarea') {
            $value = $el->textContent;
        } else if (in_array($type, ['password', 'file'], true)) {
            // Never echo passwords; file inputs have no value.
            $value = '';
        } else {
            $value = $el->getAttribute('value');
            if ($type === 'checkbox' || $type === 'radio') {
                $value = $value === '' ? 'on' : $value;
                if ($el->hasAttribute('checked')) {
                    $flags[] = 'checked';
                }
            }
        }
        if ($el->hasAttribute('disabled')) {
            $flags[] = 'disabled';
        }
        $value = $this->clean($value);
        if (mb_strlen($value) > self::MAX_VALUE_CHARS) {
            $value = mb_substr($value, 0, self::MAX_VALUE_CHARS) . '…';
        }
        return " [{$type}: {$name}" . ($value !== '' ? "=\"{$value}\"" : '') . ($flags ? ', ' . implode(', ', $flags) : '') . '] ';
    }

    /**
     * Register a link and return its id.
     *
     * @param string $href Reference.
     * @param string $text Link text.
     * @return string|null Null for script, data and in-page links.
     */
    private function link(string $href, string $text): ?string {
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        $url = self::resolve($href, $this->base);
        if ($url === '' || strpos($url, 'mailto:') === 0) {
            return null;
        }
        $key = $url . "\n" . $text;
        if (!isset($this->linkids[$key])) {
            $id = 'L' . (count($this->links) + 1);
            $this->linkids[$key] = $id;
            $this->links[$id] = ['id' => $id, 'text' => $text, 'url' => $url]
                + (strpos($url, $this->origin . '/') === 0 || $url === $this->origin ? [] : ['external' => true]);
        }
        return $this->linkids[$key];
    }

    /**
     * A short title for a form: its first heading or legend, else its action path.
     *
     * @param DOMElement $form Form.
     * @return string
     */
    private function form_title(DOMElement $form): string {
        foreach ($this->xpath->query('.//legend | .//h2 | .//h3 | .//label', $form) as $heading) {
            // Skip screen-reader-only labels (grader report cells).
            if ($heading instanceof DOMElement && !$this->hidden_by_markup($heading)) {
                return mb_substr($this->clean($heading->textContent), 0, 80);
            }
        }
        return '';
    }

    /**
     * Visible text of an element, ignoring hidden parts.
     *
     * @param DOMNode $node Node.
     * @return string
     */
    private function inline(DOMNode $node): string {
        $previous = $this->plainlinks;
        $this->plainlinks = true;
        try {
            return $this->render($node, true);
        } finally {
            $this->plainlinks = $previous;
        }
    }

    /**
     * Whether an element (by itself) is hidden or never shown.
     *
     * @param DOMElement $el Element.
     * @return bool
     */
    private function is_hidden(DOMElement $el): bool {
        return in_array(strtolower($el->tagName), self::SKIP_TAGS, true) || $this->hidden_by_markup($el);
    }

    /**
     * Whether an element is hidden by its attributes or classes.
     *
     * @param DOMElement $el Element.
     * @return bool
     */
    private function hidden_by_markup(DOMElement $el): bool {
        if (
            $el->hasAttribute('hidden') || $el->getAttribute('aria-hidden') === 'true'
                || ($el->tagName === 'input' && strtolower($el->getAttribute('type')) === 'hidden')
                || preg_match('/display\s*:\s*none/i', $el->getAttribute('style'))
        ) {
            return true;
        }
        $classes = preg_split('/\s+/', trim($el->getAttribute('class')));
        foreach (self::HIDDEN_CLASSES as $hidden) {
            if (in_array($hidden, $classes, true)) {
                if ($hidden !== 'd-none') {
                    return true;
                }
                // Data tables hidden with d-none are revealed by JavaScript once sized (e.g. the grader report).
                if ($el->tagName === 'table') {
                    return false;
                }
                // Responsive utilities: d-none d-md-block is visible on desktops.
                return !preg_grep('/^d-(sm|md|lg|xl)-(block|inline|inline-block|flex|table)$/', $classes);
            }
        }
        return false;
    }

    /**
     * Whether a form only belongs to page chrome that is never useful (footer, hidden templates).
     *
     * @param DOMElement $form Form.
     * @return bool
     */
    private function in_chrome_only(DOMElement $form): bool {
        return $this->inside('template', $form)
            || (bool)$this->first('ancestor::*[@id="page-footer" or ' . self::cls('footer-popover') . ']', $form);
    }

    /**
     * Whether a node has an ancestor with a tag name.
     *
     * @param string $tag Tag name.
     * @param DOMNode $node Node.
     * @return bool
     */
    private function inside(string $tag, DOMNode $node): bool {
        return (bool)$this->first('ancestor::' . $tag, $node);
    }

    /**
     * First node matching an XPath query.
     *
     * @param string $query Query.
     * @param DOMNode|null $context Context node.
     * @return DOMNode|null
     */
    private function first(string $query, ?DOMNode $context = null): ?DOMNode {
        $result = $context ? $this->xpath->query($query, $context) : $this->xpath->query($query);
        return $result && $result->length ? $result->item(0) : null;
    }

    /**
     * XPath predicate for an element having a class.
     *
     * @param string $class Class name.
     * @return string
     */
    public static function cls(string $class): string {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /**
     * Collapse whitespace to single spaces and trim.
     *
     * @param string $text Text.
     * @return string
     */
    private function clean(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $text)));
    }

    /**
     * Markdown code fence (three backticks).
     *
     * @return string
     */
    private static function fence(): string {
        return str_repeat(chr(96), 3);
    }

    /**
     * Tidy rendered text: trim lines, at most one blank line in a row.
     *
     * @param string $text Text.
     * @param bool $final Turn list indentation markers into spaces (only once, on the whole text).
     * @return string
     */
    private static function tidy(string $text, bool $final = true): string {
        $out = [];
        $fenced = false;
        foreach (explode("\n", str_replace("\xc2\xa0", ' ', $text)) as $line) {
            if (trim($line) === self::fence()) {
                $fenced = !$fenced;
                $out[] = self::fence();
                continue;
            }
            if ($fenced) {
                $out[] = rtrim($line);
                continue;
            }
            $out[] = trim(preg_replace('/(?<=\S) {2,}/', ' ', $line), " \t");
        }
        $text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
        return $final ? str_replace(self::INDENT, '  ', preg_replace('/(?<=' . self::INDENT . ') +/', '', $text)) : $text;
    }
}
