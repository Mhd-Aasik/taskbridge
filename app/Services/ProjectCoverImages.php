<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProjectCoverImages
{
    /** @return array{contents: string, extension: string} */
    public function prepare(mixed $cover): array
    {
        Validator::make(['cover' => $cover], ['cover' => [
            'bail', 'required', 'file', 'max:2048', 'image',
            'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp',
            'dimensions:max_width=4096,max_height=4096',
        ]])->validate();

        if (! $this->supportsProcessing()) {
            $this->invalid('Project cover processing is unavailable. Please try again later or contact support.');
        }

        $contents = @file_get_contents($cover->getRealPath());
        $information = $contents === false ? false : @getimagesizefromstring($contents);
        if (! $information || ! in_array($information[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $this->invalid('Choose a valid, complete JPEG, PNG, or WebP image.');
        }
        if (strlen($contents) > 2048 * 1024) {
            $this->invalid('The project cover must not be larger than 2 MB.');
        }
        // Some GD builds silently recover truncated JPEGs even with an error handler.
        if ($information[2] === IMAGETYPE_JPEG && strpos($contents, "\xff\xd9") === false) {
            $this->invalid('Choose a valid, complete JPEG, PNG, or WebP image.');
        }

        $allowedExtensions = match ($information[2]) {
            IMAGETYPE_JPEG => ['jpg', 'jpeg'],
            IMAGETYPE_PNG => ['png'],
            IMAGETYPE_WEBP => ['webp'],
        };
        if (! in_array(strtolower($cover->getClientOriginalExtension()), $allowedExtensions, true)) {
            $this->invalid('The image contents must match its JPEG, PNG, or WebP filename extension.');
        }

        // Allow for the decoded raster, an orientation copy, resizing, and encoder buffers.
        // Check before decoding: compressed images may need much more memory than their file size.
        $estimatedBytes = $information[0] * $information[1] * 16 + 1600 * 1000 * 8 + strlen($contents) * 4 + 16 * 1024 * 1024;
        if ($estimatedBytes > $this->availableMemoryBytes()) {
            $this->invalid('This image needs too much memory to process safely. Choose a smaller image and try again.');
        }

        $image = $this->imageOperation(fn () => imagecreatefromstring($contents));
        try {
            $this->imageOperation(fn () => imagealphablending($image, false));
            $this->imageOperation(fn () => imagesavealpha($image, true));
            if ($information[2] === IMAGETYPE_JPEG) {
                $image = $this->orient($image, $this->orientation($cover->getRealPath()));
            }
            $image = $this->resize($image);

            ob_start();
            try {
                $this->imageOperation(fn () => imagewebp($image, null, 82));
                $normalised = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($image);
        }
        if (! is_string($normalised) || $normalised === '') {
            $this->invalid('The project cover could not be processed. Choose another image and try again.');
        }

        return ['contents' => $normalised, 'extension' => 'webp'];
    }

    protected function supportsProcessing(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagewebp')
            && (imagetypes() & IMG_JPG) !== 0 && (imagetypes() & IMG_PNG) !== 0 && (imagetypes() & IMG_WEBP) !== 0;
    }

    protected function availableMemoryBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        $bytes = (int) $limit;
        if ($bytes < 0) {
            return 384 * 1024 * 1024;
        }
        $multiplier = match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        return max(0, min(384 * 1024 * 1024, $bytes * $multiplier - memory_get_usage(true)));
    }

    private function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        try {
            $metadata = @exif_read_data($path, 'IFD0', true, false);
        } catch (Throwable) {
            return 1;
        }
        $orientation = (int) ($metadata['IFD0']['Orientation'] ?? $metadata['Orientation'] ?? 1);

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            $this->imageOperation(fn () => imageflip($image, IMG_FLIP_HORIZONTAL));
        } elseif ($orientation === 4) {
            $this->imageOperation(fn () => imageflip($image, IMG_FLIP_VERTICAL));
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = $this->imageOperation(fn () => imagerotate($image, $angle, 0));
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private function resize(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 1600 / $width, 1000 / $height);
        if ($scale === 1) {
            return $image;
        }
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $resized = $this->imageOperation(fn () => imagecreatetruecolor($targetWidth, $targetHeight));
        try {
            $this->imageOperation(fn () => imagealphablending($resized, false));
            $this->imageOperation(fn () => imagesavealpha($resized, true));
            $this->imageOperation(fn () => imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height));
        } catch (Throwable $exception) {
            imagedestroy($resized);
            throw $exception;
        }
        imagedestroy($image);

        return $resized;
    }

    private function imageOperation(callable $operation): mixed
    {
        $warning = false;
        set_error_handler(function () use (&$warning): bool {
            $warning = true;

            return true;
        });
        try {
            $result = $operation();
        } catch (Throwable) {
            $result = false;
        } finally {
            restore_error_handler();
        }
        if ($result === false || $warning) {
            if ($result instanceof GdImage) {
                imagedestroy($result);
            }
            $this->invalid('Choose a valid, complete JPEG, PNG, or WebP image that can be processed safely.');
        }

        return $result;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['cover' => $message]);
    }
}
