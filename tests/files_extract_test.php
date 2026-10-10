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

namespace webservice_mcp;

use advanced_testcase;
use context_system;
use context_user;
use core_external\external_api;
use webservice_mcp\local\files\locator;
use webservice_mcp\local\files\pdf_tools;
use webservice_mcp\local\files\text_extractor;
use webservice_mcp\local\files\tools;
use webservice_mcp\local\files\transfer_exception;
use webservice_mcp\local\mcp\call_context;

/**
 * Tests for reading the content of office documents, PDFs and other formats through file_read.
 *
 * Fixtures are generated in the test: minimal OOXML/ODF packages with ZipArchive and a PDF with TCPDF.
 *
 * @package     webservice_mcp
 * @category    test
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_mcp\local\files\text_extractor
 * @covers      \webservice_mcp\local\files\pdf_tools
 * @covers      \webservice_mcp\local\files\file_reader
 */
final class files_extract_test extends advanced_testcase {
    /** WordprocessingML namespace. */
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    /** PresentationML and DrawingML namespaces. */
    private const P = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
        . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

    /** Relationships namespace. */
    private const RELS = 'xmlns="http://schemas.openxmlformats.org/package/2006/relationships"';

    /** @var \stdClass */
    private $user;

    /**
     * Log a user in.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        external_api::set_context_restriction(context_system::instance());
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
    }

    /**
     * Call file_read on a private file with the given content.
     *
     * @param string $filename File name.
     * @param string $content Bytes.
     * @param array $args Extra file_read arguments.
     * @return array CallToolResult.
     */
    private function read(string $filename, string $content, array $args = []): array {
        $fs = get_file_storage();
        $record = ['contextid' => context_user::instance($this->user->id)->id, 'component' => 'user', 'filearea' => 'private',
            'itemid' => 0, 'filepath' => '/', 'filename' => $filename];
        if ($existing = $fs->get_file(...array_values($record))) {
            $existing->delete();
        }
        $file = $fs->create_file_from_string($record, $content);
        $ctx = new call_context(call_context::ERA_MODERN, '2026-07-28', $this->user, null, null, true, 'files', [], 'f_test');
        return tools::execute('file_read', ['uri' => locator::uri_for($file)] + $args, $ctx);
    }

    /**
     * Build a zip package.
     *
     * @param array $members Member name => content.
     * @return string Zip bytes.
     */
    private function zip(array $members): string {
        $path = make_request_directory() . '/fixture.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($members as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return (string)file_get_contents($path);
    }

    /**
     * Text of a file_read result's content blocks.
     *
     * @param array $result CallToolResult.
     * @return string
     */
    private static function text(array $result): string {
        return implode("\n", array_column(array_filter($result['content'], fn($b) => $b['type'] === 'text'), 'text'));
    }

    /**
     * Word documents return paragraphs, tables, headers and footers.
     */
    public function test_docx(): void {
        $w = self::W;
        $docx = $this->zip([
            'word/document.xml' => "<w:document {$w}><w:body><w:p><w:r><w:t>Hello </w:t></w:r><w:r><w:t>Word</w:t></w:r></w:p>"
                . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>A1</w:t></w:r></w:p></w:tc>'
                . '<w:tc><w:p><w:r><w:t>B1</w:t></w:r></w:p></w:tc></w:tr>'
                . '</w:tbl><w:p><w:r><w:t>After</w:t><w:tab/><w:t>tab</w:t></w:r></w:p></w:body></w:document>',
            'word/header1.xml' => "<w:hdr {$w}><w:p><w:r><w:t>Course header</w:t></w:r></w:p></w:hdr>",
            'word/footer1.xml' => "<w:ftr {$w}><w:p><w:r><w:t>Page footer</w:t></w:r></w:p></w:ftr>",
        ]);
        $text = self::text($this->read('essay.docx', $docx));
        $this->assertStringContainsString('Text extracted from essay.docx', $text);
        $this->assertStringContainsString("Hello Word\n| A1 | B1 |\nAfter\ttab", $text);
        $this->assertStringContainsString("[Header]\nCourse header", $text);
        $this->assertStringContainsString("[Footer]\nPage footer", $text);
    }

    /**
     * PowerPoint returns slides in presentation order with speaker notes.
     */
    public function test_pptx_slides_in_order_with_notes(): void {
        $p = self::P;
        $rels = self::RELS;
        $slide = fn(string $text) => "<p:sld {$p}><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>{$text}</a:t></a:r></a:p>"
            . '</p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        $pptx = $this->zip([
            'ppt/presentation.xml' => "<p:presentation {$p}><p:sldIdLst><p:sldId id=\"256\" r:id=\"rId9\"/>"
                . '<p:sldId id="257" r:id="rId8"/></p:sldIdLst></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => "<Relationships {$rels}><Relationship Id=\"rId8\" Target=\"slides/slide1.xml\"/>"
                . '<Relationship Id="rId9" Target="slides/slide2.xml"/></Relationships>',
            'ppt/slides/slide1.xml' => $slide('Second in show'),
            'ppt/slides/slide2.xml' => $slide('Intro title'),
            'ppt/slides/_rels/slide2.xml.rels' => "<Relationships {$rels}>"
                . '<Relationship Id="rId1" Target="../notesSlides/notesSlide1.xml"/></Relationships>',
            'ppt/notesSlides/notesSlide1.xml' => "<p:notes {$p}><p:cSld><p:spTree>"
                . '<p:sp><p:nvSpPr><p:nvPr><p:ph type="sldNum"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>99</a:t></a:r></a:p>'
                . '</p:txBody></p:sp><p:sp><p:txBody><a:p><a:r><a:t>Remember the demo</a:t></a:r></a:p></p:txBody></p:sp>'
                . '</p:spTree></p:cSld></p:notes>',
        ]);
        $text = self::text($this->read('lecture.pptx', $pptx));
        $this->assertStringContainsString(
            "## Slide 1\nIntro title\nSpeaker notes:\nRemember the demo\n\n## Slide 2\nSecond in show",
            $text
        );
        // The slide-number placeholder (99) is dropped; match whole lines so ids in the URI cannot collide.
        $this->assertDoesNotMatchRegularExpression('/^99$/m', $text);
    }

    /**
     * Excel returns each sheet as TSV, with shared strings and gaps kept in place, and pages like text.
     */
    public function test_xlsx_and_paging(): void {
        $m = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $xlsx = $this->zip([
            'xl/workbook.xml' => "<workbook {$m}><sheets><sheet name=\"Marks\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>",
            'xl/_rels/workbook.xml.rels' => '<Relationships ' . self::RELS . '>'
                . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/sharedStrings.xml' => "<sst {$m}><si><t>Name</t></si><si><t>Score</t></si>"
                . '<si><r><t>An</t></r><r><t>a</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => "<worksheet {$m}><sheetData>"
                . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
                . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="C2"><v>95</v></c></row>'
                . '</sheetData></worksheet>',
        ]);
        $text = self::text($this->read('marks.xlsx', $xlsx));
        $this->assertStringContainsString("## Sheet: Marks\nName\tScore\nAna\t\t95", $text);

        set_config('inlinetextmaxbytes', 20, 'webservice_mcp');
        $first = $this->read('marks.xlsx', $xlsx);
        $this->assertSame("## Sheet: Marks\nName", $first['content'][1]['text']);
        $this->assertStringContainsString('offset=20', $first['content'][2]['text']);
        $next = $this->read('marks.xlsx', $xlsx, ['offset' => 20]);
        $this->assertStringStartsWith("\tScore", $next['content'][1]['text']);
    }

    /**
     * OpenDocument text, RTF and HTML return their text.
     */
    public function test_odt_rtf_html(): void {
        $odt = $this->zip(['content.xml' => '<office:document-content '
            . 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
            . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text>'
            . '<text:h>Title</text:h><text:p>Two<text:s text:c="2"/>spaces<text:tab/>tab</text:p>'
            . '</office:text></office:body></office:document-content>']);
        $this->assertStringContainsString("Title\nTwo  spaces\ttab", self::text($this->read('notes.odt', $odt)));

        $rtf = "{\\rtf1\\ansi{\\fonttbl{\\f0 Arial;}}{\\*\\generator Word;}\\f0 Hello \\b bold\\b0\\par Caf\\'e9 \\u8364?}";
        $this->assertStringContainsString("Hello bold\nCafé €", self::text($this->read('letter.rtf', $rtf)));

        $html = '<html><head><style>p{color:red}</style></head><body><p>First para</p><script>alert(1)</script></body></html>';
        $text = self::text($this->read('page.html', $html));
        $this->assertStringContainsString('First para', $text);
        $this->assertStringNotContainsString('<p>', $text);
        $this->assertStringNotContainsString('color:red', $text);
    }

    /**
     * PDF text comes from pdftotext when installed; otherwise the PDF is returned as a blob with a clear note.
     */
    public function test_pdf_text_or_fallback(): void {
        $result = $this->read('handout.pdf', $this->pdf('Hello PDF world'));
        if (pdf_tools::pdftotext() !== null) {
            $this->assertStringContainsString('Hello PDF world', self::text($result));
        } else {
            $this->assertStringContainsString('No text could be extracted', self::text($result));
            $this->assertSame('resource', $result['content'][1]['type']);
        }
    }

    /**
     * render="images" returns PDF pages as PNG images (skipped when the server has no renderer).
     */
    public function test_render_pdf_pages(): void {
        if (!pdf_tools::can_render()) {
            $this->markTestSkipped('No Ghostscript or pdftoppm on this test server.');
        }
        $result = $this->read('slides.pdf', $this->pdf('Page one'), ['render' => 'images', 'pages' => '1-3']);
        $images = array_values(array_filter($result['content'], fn($b) => $b['type'] === 'image'));
        $this->assertCount(1, $images);
        $this->assertSame('image/png', $images[0]['mimeType']);
        $this->assertStringStartsWith("\x89PNG", base64_decode($images[0]['data']));
        $this->assertStringContainsString('pages 1-1 of 1', $result['content'][0]['text']);
    }

    /**
     * Without a document converter, convert_to and Office rendering fail with a message naming the fix.
     */
    public function test_missing_converter_is_explained(): void {
        $docx = $this->zip(['word/document.xml' => '<w:document ' . self::W . '><w:body/></w:document>']);
        foreach ([['convert_to' => 'pdf'], ['render' => 'images']] as $args) {
            try {
                $this->read('empty.docx', $docx, $args);
                $this->fail('Expected a missing-converter error.');
            } catch (transfer_exception $e) {
                $this->assertSame('noconverter', $e->errorcode);
                $this->assertStringContainsString('Document converters', $e->getMessage());
            }
        }
        $this->expectException(transfer_exception::class);
        $this->read('bad.txt', 'plain', ['render' => 'images', 'pages' => 'all']);
    }

    /**
     * Damaged packages are not fatal and fall back to the normal binary result.
     */
    public function test_damaged_package_falls_back(): void {
        $this->assertNull((new text_extractor())->extract(make_request_directory() . '/missing.docx', 'docx'));
        $result = $this->read('broken.docx', 'not a zip');
        $this->assertSame('resource', $result['content'][1]['type']);
    }

    /**
     * A one-page PDF.
     *
     * @param string $text Page text.
     * @return string PDF bytes.
     */
    private function pdf(string $text): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->Write(10, $text);
        return $pdf->Output('', 'S');
    }
}
