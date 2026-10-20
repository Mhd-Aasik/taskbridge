<?php

namespace App\Services;

use App\Models\DeliverableVersion;
use DOMDocument;
use DOMElement;
use finfo;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class DeliverableFiles
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const EXTENSIONS = ['pdf', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'zip', 'docx', 'xlsx', 'pptx'];

    /** @return array{contents: string, extension: string, original_filename: string, mime_type: string, byte_size: int, sha256: string} */
    public function prepare(mixed $file): array
    {
        Validator::make(['file' => $file], ['file' => [
            'bail', 'required', 'file', 'max:10240', 'extensions:'.implode(',', self::EXTENSIONS),
        ]])->validate();

        // Bound the actual read as well as Laravel's reported upload size.
        $contents = @file_get_contents($file->getRealPath(), false, null, 0, self::MAX_BYTES + 1);
        if (! is_string($contents)) {
            $this->invalid('The file could not be read. Please choose it again.');
        }
        if (strlen($contents) > self::MAX_BYTES) {
            $this->invalid('The file must not be larger than 10 MiB.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $mime = match ($extension) {
            'jpg', 'jpeg', 'png', 'webp' => $this->validateImage($contents, $extension, $mime),
            'txt' => $this->validateText($contents, $mime),
            'pdf' => $this->validatePdf($contents, $mime),
            'zip', 'docx', 'xlsx', 'pptx' => $this->validateArchive($file->getRealPath(), $extension, $mime),
            default => $this->invalid('Choose a PDF, TXT, JPEG, PNG, WebP, ZIP, DOCX, XLSX, or PPTX file.'),
        };

        return [
            'contents' => $contents,
            'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            'original_filename' => $this->normaliseFilename($file->getClientOriginalName(), 255),
            'mime_type' => $mime,
            'byte_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    public function safeDownloadName(string $original, string $extension): string
    {
        $extension = strtolower($extension);
        if (! in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'bin';
        }
        $name = $this->normaliseFilename($original, 180);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = trim($stem, " .\t\n\r\0\x0B");
        if ($stem === '') {
            $stem = 'deliverable';
        }
        if (preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/i', $stem)) {
            $stem = 'deliverable-'.$stem;
        }

        return mb_strcut($stem, 0, 180 - strlen($extension) - 1, 'UTF-8').'.'.$extension;
    }

    public static function ownsPath(DeliverableVersion $version): bool
    {
        return $version->deliverable_id !== null && is_string($version->storage_path)
            && self::isGeneratedPath($version->storage_path, (string) $version->deliverable_id);
    }

    public function deleteUnreferencedFile(?string $path): void
    {
        if ($path === null || ! self::isGeneratedPath($path)) {
            return;
        }
        try {
            // Response failures and delayed rollback callbacks must preserve committed history.
            if (DeliverableVersion::query()->where('storage_path', $path)->exists()) {
                return;
            }
            if (! Storage::disk('deliverables')->delete($path)) {
                report(new RuntimeException('An unreferenced deliverable upload could not be deleted.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function isGeneratedPath(string $path, ?string $deliverableId = null): bool
    {
        $identifier = $deliverableId === null ? '[1-9][0-9]*' : preg_quote($deliverableId, '#');

        return preg_match('#\Adeliverables/'.$identifier.'/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.('.implode('|', self::EXTENSIONS).')\z#D', $path) === 1;
    }

    private function normaliseFilename(string $filename, int $maximumBytes): string
    {
        $filename = mb_convert_encoding($filename, 'UTF-8', 'UTF-8');
        $filename = str_replace('\\', '/', $filename);
        $filename = basename($filename);
        // Strip controls, bidirectional overrides, separators, and unsafe response-header bytes.
        $filename = preg_replace('/[\p{C}<>:"\/\\\\|?*]+/u', '_', $filename) ?? '';
        $filename = preg_replace('/\s+/u', ' ', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");

        return $filename === '' ? 'deliverable' : mb_strcut($filename, 0, $maximumBytes, 'UTF-8');
    }

    private function validateText(string $contents, string|false $mime): string
    {
        // fileinfo can label long lines of genuine UTF-8 text as octet-stream.
        if (! in_array($mime, ['text/plain', 'application/x-empty', 'application/octet-stream'], true)
            || ! mb_check_encoding($contents, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $contents)) {
            $this->invalid('Choose a plain UTF-8 TXT file without binary content.');
        }

        return 'text/plain';
    }

    private function validatePdf(string $contents, string|false $mime): string
    {
        if ($mime !== 'application/pdf' || ! preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)(?:\r\n|\r|\n)/', $contents)
            || ! preg_match('/\b[0-9]+\s+[0-9]+\s+obj\b/', $contents) || ! str_contains($contents, 'endobj')
            || ! preg_match('/startxref\s+([0-9]+)\s+%%EOF\s*\z/D', substr($contents, -65536), $match)) {
            $this->invalid('Choose a valid, complete PDF file whose contents match its extension.');
        }
        $offset = (int) $match[1];
        $crossReference = substr($contents, $offset, 65536);
        // Large cross-reference tables can place the trailer far beyond their first 64 KiB.
        $tail = substr($contents, -65536);
        $trailerOffset = strrpos($tail, 'trailer');
        $trailer = $trailerOffset === false ? '' : substr($tail, $trailerOffset);
        $classic = preg_match('/\Axref\s/', $crossReference) && preg_match('/\Atrailer\s*<<[\s\S]*\/Root\s+[0-9]+\s+[0-9]+\s+R\b/', $trailer);
        $stream = preg_match('/\A[0-9]+\s+[0-9]+\s+obj\s*<</', $crossReference) && preg_match('/\/Type\s*\/XRef\b/', $crossReference) && preg_match('/\/Root\s+[0-9]+\s+[0-9]+\s+R\b/', $crossReference);
        if ($offset < 1 || $offset >= strlen($contents) || (! $classic && ! $stream)) {
            $this->invalid('Choose a valid, complete PDF file whose contents match its extension.');
        }

        return 'application/pdf';
    }

    private function validateImage(string $contents, string $extension, string|false $mime): string
    {
        $expected = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };
        $information = @getimagesizefromstring($contents);
        if ($mime !== $expected || ! $information || ($information['mime'] ?? '') !== $expected) {
            $this->invalid('Choose a genuine JPEG, PNG, or WebP image matching its filename extension.');
        }
        if (! $this->supportsImages()) {
            $this->invalid('Image validation is unavailable. Please try again later or contact support.');
        }
        $width = $information[0];
        $height = $information[1];
        if ($width < 1 || $height < 1 || $width > 16384 || $height > 16384 || $width * $height > 40000000
            || $width * $height * 8 + strlen($contents) * 2 + 32 * 1024 * 1024 > $this->availableMemoryBytes()) {
            $this->invalid('This image is too large to validate safely. Choose an image up to 16,384 pixels per side and 40 megapixels, or use a smaller image.');
        }
        if (($expected === 'image/jpeg' && ! str_ends_with($contents, "\xff\xd9"))
            || ($expected === 'image/png' && ! str_ends_with($contents, "\0\0\0\0IEND\xaeB`\x82"))
            || ($expected === 'image/webp' && (strlen($contents) < 12 || unpack('Vsize', substr($contents, 4, 4))['size'] + 8 !== strlen($contents)))) {
            $this->invalid('Choose a valid, complete JPEG, PNG, or WebP image.');
        }
        $warning = false;
        set_error_handler(function () use (&$warning): bool {
            $warning = true;

            return true;
        });
        try {
            $image = imagecreatefromstring($contents);
        } catch (Throwable) {
            $image = false;
        } finally {
            restore_error_handler();
        }
        if ($image instanceof GdImage) {
            imagedestroy($image);
        }
        if (! $image || $warning) {
            $this->invalid('Choose a valid, complete JPEG, PNG, or WebP image.');
        }

        return $expected;
    }

    private function validateArchive(string $path, string $extension, string|false $mime): string
    {
        if (! $this->supportsArchives()) {
            $this->invalid('Archive validation is unavailable. Please try again later or contact support.');
        }
        $officeMimes = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        if (! in_array($mime, ['application/zip', 'application/x-zip-compressed', ...array_values($officeMimes)], true)) {
            $this->invalid('Choose a valid ZIP or Office document whose contents match its extension.');
        }
        $archive = new ZipArchive;
        if (@$archive->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $this->invalid('Choose a valid, complete, unencrypted ZIP or Office document.');
        }
        try {
            $this->checkArchiveEntries($archive);
            if ($extension === 'zip') {
                return 'application/zip';
            }
            $this->validateOfficeDocument($archive, $extension);

            return $officeMimes[$extension];
        } finally {
            $archive->close();
        }
    }

    private function checkArchiveEntries(ZipArchive $archive): void
    {
        if ($archive->numFiles > 4096) {
            $this->invalid('The archive contains too many entries. Use at most 4,096 files and folders.');
        }
        $names = [];
        $total = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);
            if (! $entry) {
                $this->invalid('The archive contains an invalid entry.');
            }
            $name = str_replace('\\', '/', $entry['name']);
            if (strlen($name) > 1024 || preg_match('#(?:\A/|\A[A-Za-z]:|(?:\A|/)\.\.(?:/|\z)|\x00)#', $name)
                || isset($names[strtolower($name)]) || ($entry['encryption_method'] ?? 0) !== 0) {
                $this->invalid('Choose an unencrypted archive with safe, distinct entry names.');
            }
            $names[strtolower($name)] = true;
            $total += $entry['size'];
            if ($entry['size'] > 64 * 1024 * 1024 || $total > 128 * 1024 * 1024
                || ($entry['size'] > 1024 * 1024 && $entry['size'] / max(1, $entry['comp_size']) > 1000)) {
                $this->invalid('The archive expands beyond safe validation limits. Use smaller files or a less compressed archive.');
            }
            if (str_ends_with($name, '/')) {
                if ($entry['size'] !== 0) {
                    $this->invalid('The archive contains invalid directory content.');
                }

                continue;
            }
            // Validate compressed bytes and CRC while streaming; never extract archive entries.
            $stream = @$archive->getStream($entry['name']);
            if (! is_resource($stream)) {
                $this->invalid('The archive contains unreadable or encrypted file content.');
            }
            $bytes = 0;
            $checksum = hash_init('crc32b');
            try {
                while (! feof($stream)) {
                    $chunk = @fread($stream, 8192);
                    if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                        $this->invalid('The archive contains damaged file content.');
                    }
                    $bytes += strlen($chunk);
                    if ($bytes > $entry['size']) {
                        $this->invalid('The archive contains invalid expanded file sizes.');
                    }
                    hash_update($checksum, $chunk);
                }
            } finally {
                fclose($stream);
            }
            if ($bytes !== $entry['size'] || hash_final($checksum) !== sprintf('%08x', $entry['crc'])) {
                $this->invalid('The archive contains damaged file content.');
            }
        }
    }

    private function validateOfficeDocument(ZipArchive $archive, string $extension): void
    {
        $types = $this->archiveXml($archive, '[Content_Types].xml', 512 * 1024);
        $relations = $this->archiveXml($archive, '_rels/.rels', 256 * 1024);
        [$part, $contentType, $root, $namespace] = match ($extension) {
            'docx' => ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', 'document', 'wordprocessingml'],
            'xlsx' => ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', 'workbook', 'spreadsheetml'],
            'pptx' => ['ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml', 'presentation', 'presentationml'],
        };
        if ($types->documentElement?->localName !== 'Types' || $types->documentElement?->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/content-types'
            || $relations->documentElement?->localName !== 'Relationships' || $relations->documentElement?->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/relationships') {
            $this->invalid('The Office document is missing valid package metadata.');
        }
        $matchingTypes = 0;
        $mainPartOverrides = 0;
        foreach ($types->documentElement->childNodes as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            if ($element->namespaceURI !== $types->documentElement->namespaceURI) {
                $this->invalid('The Office document contains invalid package metadata.');
            }
            $type = $element->getAttribute('ContentType');
            if (preg_match('/macroenabled|vbaproject|macrosheet/i', $type)) {
                $this->invalid('Macro-enabled Office documents are not supported.');
            }
            if ($element->localName === 'Override' && $element->getAttribute('PartName') === '/'.$part) {
                $mainPartOverrides++;
                if ($type === $contentType) {
                    $matchingTypes++;
                }
            }
        }
        $matchingRelations = 0;
        foreach ($relations->documentElement->childNodes as $element) {
            if (! $element instanceof DOMElement || $element->localName !== 'Relationship') {
                continue;
            }
            if ($element->namespaceURI !== $relations->documentElement->namespaceURI) {
                $this->invalid('The Office document contains invalid package relationships.');
            }
            if (in_array($element->getAttribute('Type'), [
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument',
                'http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument',
            ], true)) {
                if (ltrim($element->getAttribute('Target'), '/') !== $part || ! in_array($element->getAttribute('TargetMode'), ['', 'Internal'], true)) {
                    $this->invalid('The Office document has an invalid main-document relationship.');
                }
                $matchingRelations++;
            }
        }
        for ($index = 0; $index < $archive->numFiles; $index++) {
            if (preg_match('#(?:\A|/)vba(?:Project\.bin|Data\.xml)\z#i', $archive->getNameIndex($index))) {
                $this->invalid('Macro-enabled Office documents are not supported.');
            }
        }
        $document = $this->archiveXml($archive, $part, 4 * 1024 * 1024);
        if ($mainPartOverrides !== 1 || $matchingTypes !== 1 || $matchingRelations !== 1 || $document->documentElement?->localName !== $root
            || ! in_array($document->documentElement?->namespaceURI, [
                'http://schemas.openxmlformats.org/'.$namespace.'/2006/main',
                'http://purl.oclc.org/ooxml/'.$namespace.'/main',
            ], true)) {
            $this->invalid('The Office document contents do not match its filename extension.');
        }
    }

    private function archiveXml(ZipArchive $archive, string $name, int $maximumBytes): DOMDocument
    {
        $entry = $archive->statName($name);
        if (! $entry || $entry['size'] > $maximumBytes) {
            $this->invalid('The Office document is missing required parts or exceeds safe validation limits.');
        }
        $xml = @$archive->getFromName($name, $maximumBytes + 1);
        if (! is_string($xml) || strlen($xml) !== $entry['size'] || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
            $this->invalid('The Office document contains invalid or unsupported XML.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $valid = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        } catch (Throwable) {
            $valid = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $valid || $document->doctype !== null) {
            $this->invalid('The Office document contains invalid or unsupported XML.');
        }

        return $document;
    }

    protected function supportsImages(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring')
            && (imagetypes() & IMG_JPG) !== 0 && (imagetypes() & IMG_PNG) !== 0 && (imagetypes() & IMG_WEBP) !== 0;
    }

    protected function supportsArchives(): bool
    {
        return extension_loaded('zip') && class_exists(ZipArchive::class) && class_exists(DOMDocument::class);
    }

    protected function availableMemoryBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        $amount = (int) $limit;
        if ($amount < 0) {
            return 384 * 1024 * 1024;
        }
        $multiplier = match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        return max(0, min(384 * 1024 * 1024, $amount * $multiplier - memory_get_usage(true)));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
