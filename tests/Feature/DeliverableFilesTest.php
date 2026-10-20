<?php

namespace Tests\Feature;

use App\Models\DeliverableVersion;
use App\Services\DeliverableFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class DeliverableFilesTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('supportedFormats')]
    public function test_supported_files_preserve_original_bytes_with_server_derived_metadata(string $extension, string $mime): void
    {
        $bytes = match ($extension) {
            'txt' => "Plain-text delivery\nA second line in සිංහල.\n",
            'pdf' => $this->pdf(),
            'zip' => $this->archive(['source/README.txt' => 'An archived deliverable.', 'source/data.txt' => "one\ntwo\n"]),
            'docx', 'xlsx', 'pptx' => $this->archive($this->officeEntries($extension)),
            default => $this->image($extension),
        };
        $file = $this->upload($bytes, 'Deliverable.'.strtoupper($extension));
        $prepared = app(DeliverableFiles::class)->prepare($file);

        $this->assertSame($bytes, $prepared['contents']);
        $this->assertSame($bytes, file_get_contents($file->getRealPath()));
        $this->assertSame($extension === 'jpeg' ? 'jpg' : $extension, $prepared['extension']);
        $this->assertSame('Deliverable.'.strtoupper($extension), $prepared['original_filename']);
        $this->assertSame($mime, $prepared['mime_type']);
        $this->assertSame(strlen($bytes), $prepared['byte_size']);
        $this->assertSame(hash('sha256', $bytes), $prepared['sha256']);
    }

    #[DataProvider('invalidFiles')]
    public function test_missing_unsupported_mislabelled_and_invalid_content_is_rejected(string $case): void
    {
        $file = match ($case) {
            'missing' => null,
            'string' => 'deliverables/1/forged.txt',
            'array' => ['file' => 'forged'],
            'svg' => $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><rect width="5" height="5"/></svg>', 'image.svg'),
            'gif' => UploadedFile::fake()->image('animated.gif'),
            'html' => $this->upload('<html><body>Not plain text</body></html>', 'page.html'),
            'executable' => $this->upload('MZ'.str_repeat("\0", 100), 'application.exe'),
            'unsupported extension' => $this->upload('Plain text.', 'source.php'),
            'binary TXT' => $this->upload("Binary\0text\x01bytes", 'document.txt'),
            'invalid UTF8 TXT' => $this->upload("Invalid text \xc3\x28", 'document.txt'),
            'HTML named TXT' => $this->upload('<!doctype html><html><body>A page</body></html>', 'page.txt'),
            'image named TXT' => $this->upload($this->image('png'), 'image.txt'),
            'text named PDF' => $this->upload('This is not a PDF.', 'document.pdf'),
            'PDF header only' => $this->upload("%PDF-1.7\nThis is not a complete document.\n%%EOF", 'document.pdf'),
            'truncated PDF' => $this->upload(substr($this->pdf(), 0, -20), 'document.pdf'),
            'invalid PDF xref' => $this->upload(preg_replace('/startxref\n[0-9]+/', "startxref\n999999", $this->pdf()), 'document.pdf'),
            'PNG named JPEG' => $this->upload($this->image('png'), 'image.jpg'),
            'JPEG named WebP' => $this->upload($this->image('jpg'), 'image.webp'),
            'SVG named PNG' => $this->upload('<svg xmlns="http://www.w3.org/2000/svg"/>', 'image.png'),
            'truncated JPEG' => $this->upload(substr($this->image('jpg'), 0, -10), 'image.jpg'),
            'truncated PNG' => $this->upload(substr($this->image('png'), 0, -5), 'image.png'),
            'truncated WebP' => $this->upload(substr($this->image('webp'), 0, -5), 'image.webp'),
            'corrupt PNG' => $this->upload(substr_replace($this->image('png'), "\0\0\0\0", -16, 4), 'image.png'),
            'image with appended payload' => $this->upload($this->image('jpg').'<script>alert(1)</script>', 'image.jpg'),
            'text named ZIP' => $this->upload('This is not a ZIP archive.', 'document.zip'),
            'ZIP signature only' => $this->upload("PK\x03\x04".str_repeat("\0", 50), 'document.zip'),
            'truncated ZIP' => $this->upload(substr($this->archive(['file.txt' => 'Contents.']), 0, -10), 'document.zip'),
            'ZIP renamed DOCX' => $this->upload($this->archive(['hello.txt' => 'Not an Office package.']), 'document.docx'),
            'DOCX named XLSX' => $this->upload($this->archive($this->officeEntries('docx')), 'document.xlsx'),
            'DOCX named PDF' => $this->upload($this->archive($this->officeEntries('docx')), 'document.pdf'),
            'DOCM' => $this->upload($this->archive($this->officeEntries('docx')), 'document.docm'),
        };
        $this->assertArrayHasKey('file', $this->validation(fn () => app(DeliverableFiles::class)->prepare($file))->errors());
    }

    public function test_ten_mebibyte_boundary_and_actual_bytes_are_checked_independently_of_reported_size(): void
    {
        $bytes = str_repeat('a', 10 * 1024 * 1024);
        $prepared = app(DeliverableFiles::class)->prepare($this->upload($bytes, 'maximum.txt'));
        $this->assertSame(10 * 1024 * 1024, $prepared['byte_size']);
        $this->assertSame(hash('sha256', $bytes), $prepared['sha256']);
        $this->assertArrayHasKey('file', $this->validation(fn () => app(DeliverableFiles::class)->prepare($this->upload($bytes.'a', 'too-large.txt')->size(1)))->errors());
        $this->assertArrayHasKey('file', $this->validation(fn () => app(DeliverableFiles::class)->prepare($this->upload('A small document.', 'reported.txt')->size(10241)))->errors());
    }

    public function test_large_valid_pdf_cross_reference_tables_do_not_hide_the_trailer_from_validation(): void
    {
        $bytes = $this->pdf(5000);
        $this->assertGreaterThan(65536, strlen($bytes));
        $prepared = app(DeliverableFiles::class)->prepare($this->upload($bytes, 'large-cross-reference.pdf'));
        $this->assertSame('application/pdf', $prepared['mime_type']);
        $this->assertSame($bytes, $prepared['contents']);
        $this->assertSame(hash('sha256', $bytes), $prepared['sha256']);
    }

    #[DataProvider('invalidOfficePackages')]
    public function test_office_packages_require_real_type_relationship_and_bounded_safe_xml(string $case): void
    {
        $entries = $this->officeEntries('docx');
        match ($case) {
            'missing types' => $entries = array_diff_key($entries, ['[Content_Types].xml' => true]),
            'missing relationships' => $entries = array_diff_key($entries, ['_rels/.rels' => true]),
            'missing document' => $entries = array_diff_key($entries, ['word/document.xml' => true]),
            'empty types' => $entries['[Content_Types].xml'] = '',
            'empty relationships' => $entries['_rels/.rels'] = '',
            'empty document' => $entries['word/document.xml'] = '',
            'wrong main content type' => $entries['[Content_Types].xml'] = str_replace('wordprocessingml.document.main+xml', 'spreadsheetml.sheet.main+xml', $entries['[Content_Types].xml']),
            'duplicate main content type' => $entries['[Content_Types].xml'] = str_replace('</Types>', '<Override PartName="/word/document.xml" ContentType="application/xml"/></Types>', $entries['[Content_Types].xml']),
            'wrong types child namespace' => $entries['[Content_Types].xml'] = str_replace('<Override ', '<Override xmlns="https://example.test/fake" ', $entries['[Content_Types].xml']),
            'wrong relationship child namespace' => $entries['_rels/.rels'] = str_replace('<Relationship ', '<Relationship xmlns="https://example.test/fake" ', $entries['_rels/.rels']),
            'macro content type' => $entries['[Content_Types].xml'] = str_replace('application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', 'application/vnd.ms-word.document.macroEnabled.main+xml', $entries['[Content_Types].xml']),
            'hidden VBA' => $entries['word/vbaProject.bin'] = 'A macro payload.',
            'wrong root element' => $entries['word/document.xml'] = '<w:workbook xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
            'wrong root namespace' => $entries['word/document.xml'] = '<w:document xmlns:w="https://example.test/fake"/>',
            'malformed XML' => $entries['word/document.xml'] = '<w:document>Unclosed XML',
            'DOCTYPE' => $entries['word/document.xml'] = '<!DOCTYPE document SYSTEM "file:///not-read"><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
            'external entity' => $entries['word/document.xml'] = '<!DOCTYPE document [<!ENTITY private SYSTEM "https://example.test/never-fetch">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">&private;</w:document>',
            'external main relationship' => $entries['_rels/.rels'] = str_replace('Target="word/document.xml"', 'Target="word/document.xml" TargetMode="External"', $entries['_rels/.rels']),
            'wrong main relationship' => $entries['_rels/.rels'] = str_replace('word/document.xml', 'other/document.xml', $entries['_rels/.rels']),
            'duplicate main relationship' => $entries['_rels/.rels'] = str_replace('</Relationships>', '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>', $entries['_rels/.rels']),
            'oversized type XML' => $entries['[Content_Types].xml'] = str_replace('</Types>', '<!--'.str_repeat('metadata ', 65000).'--></Types>', $entries['[Content_Types].xml']),
            'oversized document XML' => $entries['word/document.xml'] = str_replace('</w:document>', '<!--'.str_repeat('Document content ', 270000).'--></w:document>', $entries['word/document.xml']),
        };
        $this->assertArrayHasKey('file', $this->validation(fn () => app(DeliverableFiles::class)->prepare($this->upload($this->archive($entries), 'document.docx')))->errors());
    }

    #[DataProvider('unsafeArchiveCases')]
    public function test_archives_reject_unsafe_names_damaged_data_and_expansion_abuse(string $case): void
    {
        $bytes = match ($case) {
            'parent traversal' => $this->archive(['../outside.txt' => 'Unsafe path']),
            'absolute path' => $this->archive(['/outside.txt' => 'Unsafe path']),
            'Windows path' => $this->archive(['C:\\outside.txt' => 'Unsafe path']),
            'case duplicate' => $this->archive(['file.txt' => 'one', 'FILE.txt' => 'two']),
            'too many entries' => $this->archive(array_fill_keys(array_map(fn ($number) => 'f'.$number.'.txt', range(1, 4097)), 'x')),
            'excessive compression ratio' => $this->archive(['huge.txt' => str_repeat('a', 4 * 1024 * 1024)]),
            'damaged member' => $this->damageStoredArchive(),
        };
        $this->assertArrayHasKey('file', $this->validation(fn () => app(DeliverableFiles::class)->prepare($this->upload($bytes, 'archive.zip')))->errors());
    }

    public function test_image_resource_limits_are_checked_before_decoding_and_php_limits_unchanged(): void
    {
        $processor = new class extends DeliverableFiles
        {
            protected function availableMemoryBytes(): int
            {
                return 1024;
            }
        };
        $limit = ini_get('memory_limit');
        $exception = $this->validation(fn () => $processor->prepare($this->upload($this->image('png'), 'image.png')));
        $this->assertStringContainsString('too large to validate safely', $exception->errors()['file'][0]);
        $this->assertSame($limit, ini_get('memory_limit'));
        $this->assertArrayHasKey('file', $this->validation(fn () => app(DeliverableFiles::class)->prepare($this->upload($this->image('png', 16385, 1), 'wide.png')))->errors());
    }

    public function test_empty_utf8_text_is_valid_and_no_unadvertised_minimum_file_size_is_added(): void
    {
        $prepared = app(DeliverableFiles::class)->prepare($this->upload('', 'empty.txt'));
        $this->assertSame('', $prepared['contents']);
        $this->assertSame('text/plain', $prepared['mime_type']);
        $this->assertSame(0, $prepared['byte_size']);
        $this->assertSame(hash('sha256', ''), $prepared['sha256']);
    }

    public function test_decoder_warnings_restore_the_previous_error_handler(): void
    {
        $seen = [];
        set_error_handler(function ($level, $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });
        try {
            $png = substr_replace($this->image('png'), "\0\0\0\0", -16, 4);
            $this->validation(fn () => app(DeliverableFiles::class)->prepare($this->upload($png, 'damaged.png')));
            trigger_error('deliverable handler restored', E_USER_NOTICE);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(['deliverable handler restored'], $seen);
    }

    public function test_unavailable_processing_support_returns_actionable_validation(): void
    {
        $processor = new class extends DeliverableFiles
        {
            protected function supportsImages(): bool
            {
                return false;
            }

            protected function supportsArchives(): bool
            {
                return false;
            }
        };
        $this->assertStringContainsString('validation is unavailable', $this->validation(fn () => $processor->prepare($this->upload($this->image('png'), 'image.png')))->errors()['file'][0]);
        $this->assertStringContainsString('validation is unavailable', $this->validation(fn () => $processor->prepare($this->upload($this->archive(['file.txt' => 'Text.']), 'archive.zip')))->errors()['file'][0]);
    }

    public function test_original_filename_and_download_name_remove_unsafe_bytes_and_remain_bounded(): void
    {
        $processor = app(DeliverableFiles::class);
        $prepared = $processor->prepare($this->upload('Plain-text delivery.', str_repeat('界', 100).'.txt'));
        $this->assertLessThanOrEqual(255, strlen($prepared['original_filename']));
        $this->assertTrue(mb_check_encoding($prepared['original_filename'], 'UTF-8'));
        $name = $processor->safeDownloadName("C:\\private\\<report>\r\n\"unsafe\"\u{202E}exe.txt", 'txt');
        $this->assertSame('_report_unsafe_exe.txt', $name);
        $this->assertStringNotContainsString('private', $name);
        $this->assertDoesNotMatchRegularExpression('/[\p{C}<>:"\/\\\\|?*]/u', $name);
        $this->assertSame('deliverable.pdf', $processor->safeDownloadName('....', 'pdf'));
        $this->assertLessThanOrEqual(180, strlen($processor->safeDownloadName(str_repeat('界', 200).'.txt', 'txt')));
        $this->assertSame('invoice.pdf', $processor->safeDownloadName('invoice.exe', 'pdf'));
        $this->assertSame('invoice.bin', $processor->safeDownloadName('invoice.exe', '../forged'));
        $this->assertSame('deliverable-CON.pdf', $processor->safeDownloadName('CON.pdf', 'pdf'));
    }

    public function test_owned_paths_only_accept_generated_paths_for_the_same_deliverable(): void
    {
        $version = new DeliverableVersion;
        $version->deliverable_id = 37;
        $version->storage_path = 'deliverables/37/'.Str::uuid().'.pdf';
        $this->assertTrue(DeliverableFiles::ownsPath($version));
        foreach (['deliverables/38/'.Str::uuid().'.pdf', 'deliverables/37/../../private.pdf', 'deliverables/37/'.Str::uuid().'.php', 'deliverables/37/not-a-uuid.pdf', '/deliverables/37/'.Str::uuid().'.pdf'] as $path) {
            $version->storage_path = $path;
            $this->assertFalse(DeliverableFiles::ownsPath($version));
        }
    }

    public function test_cleanup_deletes_only_unreferenced_generated_files_and_preserves_committed_versions(): void
    {
        Storage::fake('deliverables');
        $version = DeliverableVersion::factory()->create();
        Storage::disk('deliverables')->put($version->storage_path, 'Immutable committed contents.');
        $unreferenced = 'deliverables/'.$version->deliverable_id.'/'.Str::uuid().'.txt';
        Storage::disk('deliverables')->put($unreferenced, 'Rolled-back upload.');
        Storage::disk('deliverables')->put('private.txt', 'Unrelated file.');
        $processor = app(DeliverableFiles::class);
        $processor->deleteUnreferencedFile($version->storage_path);
        $processor->deleteUnreferencedFile($unreferenced);
        $processor->deleteUnreferencedFile($unreferenced);
        $processor->deleteUnreferencedFile('private.txt');
        $processor->deleteUnreferencedFile(null);
        Storage::disk('deliverables')->assertExists([$version->storage_path, 'private.txt']);
        Storage::disk('deliverables')->assertMissing($unreferenced);
    }

    private function image(string $extension, int $width = 80, int $height = 50): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 180, 170));
        ob_start();
        try {
            match ($extension) {
                'jpg', 'jpeg' => imagejpeg($image),
                'png' => imagepng($image),
                'webp' => imagewebp($image),
            };

            return ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    private function pdf(int $entryCount = 5): string
    {
        $contents = "%PDF-1.7\n%\xe2\xe3\xcf\xd3\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',
            "<< /Length 0 >>\nstream\n\nendstream",
        ];
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($contents);
            $contents .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($contents);
        $contents .= "xref\n0 ".$entryCount."\n".sprintf('%010d 65535 f ', $entryCount > 5 ? 5 : 0)."\n";
        foreach (array_slice($offsets, 1) as $offset) {
            $contents .= sprintf('%010d 00000 n ', $offset)."\n";
        }
        for ($index = 5; $index < $entryCount; $index++) {
            $contents .= sprintf('%010d 00000 f ', $index + 1 < $entryCount ? $index + 1 : 0)."\n";
        }

        return $contents."trailer\n<< /Size ".$entryCount." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** @return array<string, string> */
    private function officeEntries(string $extension): array
    {
        [$part, $type, $main] = match ($extension) {
            'docx' => ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Delivered report</w:t></w:r></w:p></w:body></w:document>'],
            'xlsx' => ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>'],
            'pptx' => ['ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml', '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst><p:sldSz cx="9144000" cy="6858000"/></p:presentation>'],
        };
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/'.$part.'" ContentType="'.$type.'"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="'.$part.'"/></Relationships>',
            $part => '<?xml version="1.0" encoding="UTF-8"?>'.$main,
        ];
        if ($extension === 'xlsx') {
            $entries['[Content_Types].xml'] = str_replace('</Types>', '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>', $entries['[Content_Types].xml']);
            $entries['xl/_rels/workbook.xml.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
            $entries['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Delivery</t></is></c></row></sheetData></worksheet>';
        }
        if ($extension === 'pptx') {
            $entries['[Content_Types].xml'] = str_replace('</Types>', '<Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/></Types>', $entries['[Content_Types].xml']);
            $entries['ppt/_rels/presentation.xml.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/></Relationships>';
            $entries['ppt/slides/slide1.xml'] = '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree/></p:cSld></p:sld>';
        }

        return $entries;
    }

    /** @param array<string, string> $entries */
    private function archive(array $entries, bool $stored = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tb-archive-');
        try {
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path, ZipArchive::OVERWRITE) === true);
            foreach ($entries as $name => $contents) {
                $archive->addFromString($name, $contents);
                if ($stored) {
                    $archive->setCompressionName($name, ZipArchive::CM_STORE);
                }
            }
            $archive->close();

            return file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function damageStoredArchive(): string
    {
        $contents = $this->archive(['file.txt' => 'Unique uncompressed member contents.'], true);

        return str_replace('Unique uncompressed member contents.', 'Broken uncompressed member contents.', $contents);
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function validation(callable $operation): ValidationException
    {
        try {
            $operation();
            $this->fail('Expected file validation failure.');
        } catch (ValidationException $exception) {
            return $exception;
        }
    }

    public static function supportedFormats(): array
    {
        return [
            ['txt', 'text/plain'], ['pdf', 'application/pdf'], ['jpg', 'image/jpeg'], ['jpeg', 'image/jpeg'],
            ['png', 'image/png'], ['webp', 'image/webp'], ['zip', 'application/zip'],
            ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        ];
    }

    public static function invalidFiles(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'string', 'array', 'svg', 'gif', 'html', 'executable',
            'unsupported extension', 'binary TXT', 'invalid UTF8 TXT', 'HTML named TXT', 'image named TXT',
            'text named PDF', 'PDF header only', 'truncated PDF', 'invalid PDF xref', 'PNG named JPEG',
            'JPEG named WebP', 'SVG named PNG', 'truncated JPEG', 'truncated PNG', 'truncated WebP', 'corrupt PNG',
            'image with appended payload', 'text named ZIP', 'ZIP signature only', 'truncated ZIP', 'ZIP renamed DOCX',
            'DOCX named XLSX', 'DOCX named PDF', 'DOCM']);
    }

    public static function invalidOfficePackages(): array
    {
        return array_map(fn ($case) => [$case], ['missing types', 'missing relationships', 'missing document', 'empty types', 'empty relationships', 'empty document',
            'wrong main content type', 'duplicate main content type', 'wrong types child namespace', 'wrong relationship child namespace',
            'macro content type', 'hidden VBA', 'wrong root element', 'wrong root namespace',
            'malformed XML', 'DOCTYPE', 'external entity', 'external main relationship', 'wrong main relationship',
            'duplicate main relationship', 'oversized type XML', 'oversized document XML']);
    }

    public static function unsafeArchiveCases(): array
    {
        return array_map(fn ($case) => [$case], ['parent traversal', 'absolute path', 'Windows path', 'case duplicate',
            'too many entries', 'excessive compression ratio', 'damaged member']);
    }
}
