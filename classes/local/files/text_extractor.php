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

namespace webservice_mcp\local\files;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

/**
 * Plain-text extraction for office documents and other common formats, in pure PHP (no converters, no shell).
 *
 * docx (paragraphs, tables, headers, footers, footnotes), pptx (slides in order, speaker notes), xlsx (sheets as
 * TSV with shared strings), odt/odp/ods, rtf and html. PDF text comes from pdftotext when installed (see
 * pdf_tools). Archive members are size-capped so a zip bomb cannot exhaust memory.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_extractor {
    /** Largest archive member read, uncompressed. */
    private const MAX_MEMBER = 52428800;

    /** Largest total uncompressed bytes read from one archive. */
    private const MAX_TOTAL = 209715200;

    /** Most cells kept per spreadsheet row (guards against huge repeated empty ranges). */
    private const MAX_COLUMNS = 1000;

    /** Extensions handled here, mapped to their extractor. */
    private const FORMATS = [
        'docx' => 'docx', 'docm' => 'docx', 'dotx' => 'docx',
        'pptx' => 'pptx', 'pptm' => 'pptx', 'ppsx' => 'pptx',
        'xlsx' => 'xlsx', 'xlsm' => 'xlsx',
        'odt' => 'odf', 'odp' => 'odf', 'ods' => 'odf', 'ott' => 'odf', 'otp' => 'odf', 'ots' => 'odf',
        'rtf' => 'rtf',
        'html' => 'html', 'htm' => 'html', 'xhtml' => 'html',
        'pdf' => 'pdf',
    ];

    /** ODF text namespace. */
    private const ODF_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    /** @var int Uncompressed bytes read from the current archive. */
    private int $read = 0;

    /**
     * Which extractor handles a file, or null.
     *
     * @param string $filename File name.
     * @param string $mimetype Mimetype.
     * @return string|null docx, pptx, xlsx, odf, rtf, html or pdf.
     */
    public static function format(string $filename, string $mimetype): ?string {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (isset(self::FORMATS[$extension])) {
            return self::FORMATS[$extension];
        }
        $bymime = ['application/pdf' => 'pdf', 'text/html' => 'html', 'application/rtf' => 'rtf', 'text/rtf' => 'rtf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.oasis.opendocument.text' => 'odf',
            'application/vnd.oasis.opendocument.presentation' => 'odf',
            'application/vnd.oasis.opendocument.spreadsheet' => 'odf'];
        return $bymime[$mimetype] ?? null;
    }

    /**
     * Extract text from a local file.
     *
     * @param string $path Local file path.
     * @param string $format Result of format().
     * @return string|null Text, or null when nothing could be extracted (not a valid file, no tool for PDF).
     */
    public function extract(string $path, string $format): ?string {
        $this->read = 0;
        switch ($format) {
            case 'docx':
            case 'pptx':
            case 'xlsx':
            case 'odf':
                $zip = new ZipArchive();
                if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                    return null;
                }
                try {
                    $text = $this->$format($zip);
                } finally {
                    $zip->close();
                }
                break;
            case 'rtf':
                $text = self::rtf((string)file_get_contents($path));
                break;
            case 'html':
                // Strip scripts and styles first: html2text's own patterns miss multi-line blocks.
                $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', (string)file_get_contents($path));
                $text = html_to_text((string)$html, 0, true);
                break;
            case 'pdf':
                $text = pdf_tools::to_text($path);
                break;
            default:
                return null;
        }
        $text = $text === null ? null : trim(preg_replace("/\n{3,}/", "\n\n", fix_utf8($text)));
        return $text === '' ? null : $text;
    }

    /**
     * Word: body, then headers, footers, footnotes and endnotes.
     *
     * @param ZipArchive $zip Archive.
     * @return string|null
     */
    private function docx(ZipArchive $zip): ?string {
        $body = $this->xml($zip, 'word/document.xml');
        if (!$body) {
            return null;
        }
        $parts = [$this->ooxml_blocks($body, 'w')];
        $extras = ['header' => 'Header', 'footer' => 'Footer', 'footnotes' => 'Footnotes', 'endnotes' => 'Endnotes'];
        foreach ($extras as $prefix => $label) {
            foreach ($this->members($zip, '~^word/' . $prefix . '\d*\.xml$~') as $name) {
                $text = trim($this->ooxml_blocks($this->xml($zip, $name), 'w'));
                if ($text !== '') {
                    $parts[] = "[{$label}]\n{$text}";
                }
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * PowerPoint: slides in presentation order with "Slide N" headings and speaker notes.
     *
     * @param ZipArchive $zip Archive.
     * @return string|null
     */
    private function pptx(ZipArchive $zip): ?string {
        $slides = [];
        $presentation = $this->xml($zip, 'ppt/presentation.xml');
        $rels = $this->rels($zip, 'ppt/_rels/presentation.xml.rels', 'ppt/');
        if ($presentation) {
            $xpath = $this->xpath($presentation);
            foreach ($xpath->query('//p:sldIdLst/p:sldId') as $sld) {
                $rid = $sld->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if (isset($rels[$rid])) {
                    $slides[] = $rels[$rid];
                }
            }
        }
        if (!$slides) {
            $slides = $this->members($zip, '~^ppt/slides/slide\d+\.xml$~');
        }
        $out = [];
        foreach ($slides as $index => $name) {
            $doc = $this->xml($zip, $name);
            if (!$doc) {
                continue;
            }
            $text = "## Slide " . ($index + 1) . "\n" . $this->drawingml_text($doc);
            $slidedir = dirname($name);
            foreach ($this->rels($zip, $slidedir . '/_rels/' . basename($name) . '.rels', $slidedir . '/') as $target) {
                if (strpos($target, 'notesSlide') !== false && ($notes = $this->xml($zip, $target))) {
                    $notestext = trim($this->drawingml_text($notes, true));
                    if ($notestext !== '') {
                        $text .= "\nSpeaker notes:\n" . $notestext;
                    }
                }
            }
            $out[] = rtrim($text);
        }
        return $out ? implode("\n\n", $out) : null;
    }

    /**
     * Excel: each sheet as "## Sheet: name" followed by tab-separated rows.
     *
     * @param ZipArchive $zip Archive.
     * @return string|null
     */
    private function xlsx(ZipArchive $zip): ?string {
        $shared = [];
        if ($strings = $this->xml($zip, 'xl/sharedStrings.xml')) {
            $sxpath = $this->xpath($strings);
            foreach ($sxpath->query('//m:si') as $si) {
                $shared[] = $this->concat_text($sxpath, $si, 't');
            }
        }
        $workbook = $this->xml($zip, 'xl/workbook.xml');
        if (!$workbook) {
            return null;
        }
        $rels = $this->rels($zip, 'xl/_rels/workbook.xml.rels', 'xl/');
        $out = [];
        foreach ($this->xpath($workbook)->query('//m:sheets/m:sheet') as $sheet) {
            $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $doc = isset($rels[$rid]) ? $this->xml($zip, $rels[$rid]) : null;
            if (!$doc) {
                continue;
            }
            $rows = [];
            $xpath = $this->xpath($doc);
            foreach ($xpath->query('//m:sheetData/m:row') as $row) {
                $cells = [];
                foreach ($xpath->query('m:c', $row) as $c) {
                    $col = preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $m) ? self::column_index($m[1]) : count($cells);
                    if ($col >= self::MAX_COLUMNS) {
                        continue;
                    }
                    $type = $c->getAttribute('t');
                    $v = $xpath->query('m:v', $c)->item(0);
                    $value = $v ? $v->textContent : '';
                    if ($type === 's') {
                        $value = $shared[(int)$value] ?? '';
                    } else if ($type === 'inlineStr') {
                        $value = $this->concat_text($xpath, $c, 't');
                    } else if ($type === 'b') {
                        $value = $value === '1' ? 'TRUE' : 'FALSE';
                    }
                    $cells[$col] = str_replace(["\t", "\r", "\n"], ' ', $value);
                }
                if ($cells) {
                    $line = array_fill(0, max(array_keys($cells)) + 1, '');
                    $rows[] = implode("\t", array_replace($line, $cells));
                }
            }
            $out[] = "## Sheet: " . $sheet->getAttribute('name') . "\n" . implode("\n", $rows);
        }
        return $out ? implode("\n\n", $out) : null;
    }

    /**
     * OpenDocument text, presentation or spreadsheet (content.xml).
     *
     * @param ZipArchive $zip Archive.
     * @return string|null
     */
    private function odf(ZipArchive $zip): ?string {
        $doc = $this->xml($zip, 'content.xml');
        if (!$doc) {
            return null;
        }
        $body = $doc->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:office:1.0', 'body')->item(0);
        return $body ? $this->odf_walk($body) : null;
    }

    /**
     * Render ODF elements as text: paragraphs, headings, lists, tables (TSV), slides and notes.
     *
     * @param DOMNode $node Node.
     * @return string
     */
    private function odf_walk(DOMNode $node): string {
        $out = '';
        $slide = 0;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'p':
                case 'h':
                    $out .= $this->odf_inline($child) . "\n";
                    break;
                case 'page':
                    $slide++;
                    $name = $child->getAttributeNS('urn:oasis:names:tc:opendocument:xmlns:drawing:1.0', 'name');
                    $out .= "\n## Slide {$slide}" . ($name !== '' && !preg_match('/^page\d+$/', $name) ? " ({$name})" : '')
                        . "\n" . $this->odf_walk($child);
                    break;
                case 'notes':
                    $notes = trim($this->odf_walk($child));
                    $out .= $notes !== '' ? "Speaker notes:\n{$notes}\n" : '';
                    break;
                case 'table':
                    $name = $child->getAttributeNS('urn:oasis:names:tc:opendocument:xmlns:table:1.0', 'name');
                    $label = $child->parentNode->localName === 'spreadsheet' ? 'Sheet' : 'Table';
                    $out .= "\n## {$label}: {$name}\n" . $this->odf_table($child) . "\n";
                    break;
                default:
                    $out .= $this->odf_walk($child);
            }
        }
        return $out;
    }

    /**
     * ODF table rows as TSV, honouring repeated cells and rows without expanding empty ranges.
     *
     * @param DOMElement $table Table element.
     * @return string
     */
    private function odf_table(DOMElement $table): string {
        $ns = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
        $rows = [];
        foreach ($table->getElementsByTagNameNS($ns, 'table-row') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof DOMElement || !in_array($cell->localName, ['table-cell', 'covered-table-cell'], true)) {
                    continue;
                }
                $text = str_replace(["\t", "\n"], ' ', trim($this->odf_walk($cell)));
                $repeat = max(1, (int)$cell->getAttributeNS($ns, 'number-columns-repeated'));
                for ($i = 0; $i < $repeat && count($cells) < self::MAX_COLUMNS; $i++) {
                    $cells[] = $text;
                }
            }
            while ($cells && end($cells) === '') {
                array_pop($cells);
            }
            if ($cells) {
                $rows[] = implode("\t", $cells);
            }
        }
        return implode("\n", $rows);
    }

    /**
     * Inline ODF paragraph text with spaces, tabs and line breaks.
     *
     * @param DOMNode $node Paragraph or span.
     * @return string
     */
    private function odf_inline(DOMNode $node): string {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= $child->nodeValue;
            } else if ($child instanceof DOMElement) {
                if ($child->localName === 's') {
                    $out .= str_repeat(' ', max(1, (int)$child->getAttributeNS(self::ODF_TEXT, 'c')));
                } else if ($child->localName === 'tab') {
                    $out .= "\t";
                } else if ($child->localName === 'line-break') {
                    $out .= "\n";
                } else if ($child->localName !== 'annotation') {
                    $out .= $this->odf_inline($child);
                }
            }
        }
        return $out;
    }

    /**
     * WordprocessingML paragraphs and tables in document order.
     *
     * @param DOMDocument|null $doc Part.
     * @param string $prefix Namespace prefix registered in xpath() (w).
     * @return string
     */
    private function ooxml_blocks(?DOMDocument $doc, string $prefix): string {
        if (!$doc) {
            return '';
        }
        $xpath = $this->xpath($doc);
        $out = [];
        $blocks = $xpath->query("//{$prefix}:p[not(ancestor::{$prefix}:tbl)] | //{$prefix}:tbl[not(ancestor::{$prefix}:tbl)]");
        foreach ($blocks as $block) {
            if ($block->localName === 'tbl') {
                foreach ($xpath->query("{$prefix}:tr", $block) as $tr) {
                    $cells = [];
                    foreach ($xpath->query("{$prefix}:tc", $tr) as $tc) {
                        $paras = [];
                        foreach ($xpath->query(".//{$prefix}:p", $tc) as $p) {
                            $paras[] = $this->word_paragraph($p);
                        }
                        $cells[] = trim(implode(' ', $paras));
                    }
                    $out[] = '| ' . implode(' | ', $cells) . ' |';
                }
            } else {
                $out[] = $this->word_paragraph($block);
            }
        }
        return implode("\n", $out);
    }

    /**
     * Text of one Word paragraph, with tabs and breaks.
     *
     * @param DOMNode $p w:p element.
     * @return string
     */
    private function word_paragraph(DOMNode $p): string {
        $out = '';
        $walk = function (DOMNode $node) use (&$walk, &$out) {
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->localName === 't') {
                    $out .= $child->textContent;
                } else if ($child->localName === 'tab') {
                    $out .= "\t";
                } else if ($child->localName === 'br' || $child->localName === 'cr') {
                    $out .= "\n";
                } else if ($child->localName !== 'instrText' && $child->localName !== 'delText') {
                    $walk($child);
                }
            }
        };
        $walk($p);
        return $out;
    }

    /**
     * DrawingML text (slides, notes): one line per a:p.
     *
     * @param DOMDocument $doc Slide or notes part.
     * @param bool $skipnumbers Skip slide-number placeholders (notes pages repeat them).
     * @return string
     */
    private function drawingml_text(DOMDocument $doc, bool $skipnumbers = false): string {
        $xpath = $this->xpath($doc);
        $lines = [];
        foreach ($xpath->query('//p:sp') as $shape) {
            $placeholder = $xpath->query('.//p:nvPr/p:ph', $shape)->item(0);
            $phtype = $placeholder instanceof DOMElement ? $placeholder->getAttribute('type') : '';
            if ($skipnumbers && in_array($phtype, ['sldNum', 'sldImg', 'hdr', 'ftr', 'dt'], true)) {
                continue;
            }
            foreach ($xpath->query('.//a:p', $shape) as $p) {
                $line = '';
                foreach ($xpath->query('.//a:t | .//a:br', $p) as $part) {
                    $line .= $part->localName === 'br' ? "\n" : $part->textContent;
                }
                if (trim($line) !== '') {
                    $lines[] = $line;
                }
            }
        }
        foreach ($xpath->query('//a:tbl/a:tr') as $tr) {
            $cells = [];
            foreach ($xpath->query('a:tc', $tr) as $tc) {
                $cells[] = trim($this->concat_text($xpath, $tc, 't', 'a'));
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }
        return implode("\n", $lines);
    }

    /**
     * Basic RTF to text: drops control words and destinations, keeps paragraphs, tabs, hex and unicode escapes.
     *
     * @param string $rtf RTF source.
     * @return string
     */
    public static function rtf(string $rtf): string {
        $out = '';
        $stack = [];
        $skip = false;
        $ucskip = 1;
        $pendingskip = 0;
        $len = strlen($rtf);
        $destinations = ['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'header', 'footer', 'object', 'themedata',
            'colorschememapping', 'latentstyles', 'datastore', 'xmlnstbl', 'listtable', 'listoverridetable', 'rsidtbl',
            'generator', 'filetbl', 'revtbl'];
        for ($i = 0; $i < $len; $i++) {
            $c = $rtf[$i];
            if ($c === '{') {
                $stack[] = $skip;
            } else if ($c === '}') {
                $skip = $stack ? array_pop($stack) : false;
            } else if ($c === '\\') {
                $next = $rtf[$i + 1] ?? '';
                if ($next === '\\' || $next === '{' || $next === '}') {
                    $out .= $skip ? '' : $next;
                    $i++;
                } else if ($next === '*') {
                    $skip = true;
                    $i++;
                } else if ($next === "'") {
                    if ($skip) {
                        $i += 3;
                        continue;
                    }
                    if ($pendingskip > 0) {
                        $pendingskip--;
                    } else {
                        $out .= \core_text::convert(chr((int)hexdec(substr($rtf, $i + 2, 2))), 'windows-1252', 'utf-8');
                    }
                    $i += 3;
                } else if (preg_match('/\G\\\\([a-z]{1,32})(-?\d{1,10})? ?/', $rtf, $m, 0, $i)) {
                    $i += strlen($m[0]) - 1;
                    $word = $m[1];
                    $arg = $m[2] ?? '';
                    if (in_array($word, $destinations, true)) {
                        $skip = true;
                    } else if ($skip) {
                        continue;
                    } else if ($word === 'par' || $word === 'line' || $word === 'row' || $word === 'sect' || $word === 'page') {
                        $out .= "\n";
                    } else if ($word === 'tab' || $word === 'cell') {
                        $out .= "\t";
                    } else if ($word === 'uc') {
                        $ucskip = (int)$arg;
                    } else if ($word === 'u') {
                        $code = (int)$arg;
                        $out .= \core_text::code2utf8($code < 0 ? $code + 65536 : $code);
                        $pendingskip = $ucskip;
                    }
                } else {
                    $i++;
                }
            } else if (!$skip && $c !== "\r" && $c !== "\n") {
                if ($pendingskip > 0) {
                    $pendingskip--;
                    continue;
                }
                $out .= $c;
            }
        }
        return $out;
    }

    /**
     * Concatenated text of descendant elements with a local name (e.g. all t inside a shared string).
     *
     * @param DOMXPath $xpath XPath for the node's document.
     * @param DOMNode $node Node.
     * @param string $localname Local name of text elements.
     * @param string $prefix Namespace prefix (m for SpreadsheetML, a for DrawingML).
     * @return string
     */
    private function concat_text(DOMXPath $xpath, DOMNode $node, string $localname, string $prefix = 'm'): string {
        $out = '';
        foreach ($xpath->query(".//{$prefix}:{$localname}", $node) as $t) {
            $out .= $t->textContent;
        }
        return $out;
    }

    /**
     * Relationship targets of a part, keyed by relationship id, resolved to archive paths.
     *
     * @param ZipArchive $zip Archive.
     * @param string $relspath .rels part.
     * @param string $base Directory the targets are relative to.
     * @return array
     */
    private function rels(ZipArchive $zip, string $relspath, string $base): array {
        $doc = $this->xml($zip, $relspath);
        $targets = [];
        if ($doc) {
            foreach ($doc->getElementsByTagName('Relationship') as $rel) {
                $target = $rel->getAttribute('Target');
                $path = strpos($target, '/') === 0 ? ltrim($target, '/') : $base . $target;
                // Resolve "../" segments.
                $segments = [];
                foreach (explode('/', $path) as $segment) {
                    if ($segment === '..') {
                        array_pop($segments);
                    } else if ($segment !== '.' && $segment !== '') {
                        $segments[] = $segment;
                    }
                }
                $targets[$rel->getAttribute('Id')] = implode('/', $segments);
            }
        }
        return $targets;
    }

    /**
     * Archive members matching a pattern, in natural order.
     *
     * @param ZipArchive $zip Archive.
     * @param string $pattern Regex.
     * @return string[]
     */
    private function members(ZipArchive $zip, string $pattern): array {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match($pattern, $name)) {
                $names[] = $name;
            }
        }
        natsort($names);
        return array_values($names);
    }

    /**
     * Parse an archive member as XML, within the size budget, without network access or entity expansion.
     *
     * @param ZipArchive $zip Archive.
     * @param string $name Member name.
     * @return DOMDocument|null
     */
    private function xml(ZipArchive $zip, string $name): ?DOMDocument {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > self::MAX_MEMBER || $this->read + $stat['size'] > self::MAX_TOTAL) {
            return null;
        }
        $this->read += (int)$stat['size'];
        $content = $zip->getFromName($name);
        if ($content === false || $content === '') {
            return null;
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($content, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $doc : null;
    }

    /**
     * XPath with the OOXML prefixes registered.
     *
     * @param DOMDocument $doc Document.
     * @return DOMXPath
     */
    private function xpath(DOMDocument $doc): DOMXPath {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return $xpath;
    }

    /**
     * Zero-based column index of a spreadsheet column name (A = 0, AA = 26).
     *
     * @param string $letters Column letters.
     * @return int
     */
    private static function column_index(string $letters): int {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }
        return $index - 1;
    }
}
