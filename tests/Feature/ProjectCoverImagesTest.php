<?php

namespace Tests\Feature;

use App\Services\ProjectCoverImages;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectCoverImagesTest extends TestCase
{
    #[DataProvider('formats')]
    public function test_supported_images_are_reencoded_as_webp_without_upscaling(string $format, string $extension): void
    {
        $file = $this->upload($this->image($format, 320, 180), 'cover.'.$extension);
        $before = file_get_contents($file->getRealPath());
        $prepared = app(ProjectCoverImages::class)->prepare($file);

        $this->assertSame('webp', $prepared['extension']);
        $this->assertSame([320, 180], array_slice(getimagesizefromstring($prepared['contents']), 0, 2));
        $this->assertSame('image/webp', getimagesizefromstring($prepared['contents'])['mime']);
        $this->assertSame($before, file_get_contents($file->getRealPath()));
    }

    #[DataProvider('resizeCases')]
    public function test_large_images_fit_the_bounding_box_without_stretching(int $width, int $height, int $expectedWidth, int $expectedHeight): void
    {
        $prepared = app(ProjectCoverImages::class)->prepare($this->upload($this->image('png', $width, $height), 'cover.png'));
        $this->assertSame([$expectedWidth, $expectedHeight], array_slice(getimagesizefromstring($prepared['contents']), 0, 2));
    }

    public function test_transparency_is_preserved_when_resizing_png_images(): void
    {
        $image = imagecreatetruecolor(2000, 1000);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 500, 250, 1499, 749, imagecolorallocatealpha($image, 10, 180, 170, 0));
        $prepared = app(ProjectCoverImages::class)->prepare($this->upload($this->encode($image, 'png'), 'transparent.png'));
        imagedestroy($image);
        $normalised = imagecreatefromstring($prepared['contents']);
        try {
            $this->assertSame([1600, 800], [imagesx($normalised), imagesy($normalised)]);
            $this->assertSame(127, imagecolorsforindex($normalised, imagecolorat($normalised, 0, 0))['alpha']);
            $this->assertSame(0, imagecolorsforindex($normalised, imagecolorat($normalised, 800, 400))['alpha']);
        } finally {
            imagedestroy($normalised);
        }
    }

    #[DataProvider('invalidFiles')]
    public function test_unsupported_forged_corrupted_and_oversized_images_are_rejected(string $case): void
    {
        $png = $this->image('png', 80, 50);
        $jpeg = $this->image('jpg', 80, 50);
        $invalid = match ($case) {
            'missing' => null,
            'string' => 'not an uploaded image',
            'array' => ['cover' => 'forged'],
            'SVG' => $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>', 'cover.svg'),
            'GIF' => $this->upload($this->image('gif', 80, 50), 'cover.gif'),
            'text named PNG' => $this->upload('This is not an image.', 'cover.png'),
            'fake JPEG' => $this->upload("\xff\xd8Invalid JPEG content", 'cover.jpg'),
            'PNG named JPEG' => $this->upload($png, 'cover.jpg'),
            'JPEG named PNG' => $this->upload($jpeg, 'cover.png'),
            'PNG named WebP' => $this->upload($png, 'cover.webp'),
            'unsupported extension' => $this->upload($png, 'cover.svg'),
            'truncated PNG' => $this->upload(substr($png, 0, 33), 'cover.png'),
            'truncated JPEG' => $this->upload(substr($jpeg, 0, -20), 'cover.jpg'),
            'corrupt PNG checksum' => $this->upload(substr_replace($png, "\0\0\0\0", -16, 4), 'cover.png'),
            'reported size' => $this->upload($png, 'cover.png')->size(2049),
            'actual size' => $this->upload($png.str_repeat('x', 2048 * 1024), 'cover.png')->size(1),
            'width' => $this->upload($this->image('png', 4097, 1), 'cover.png'),
            'height' => $this->upload($this->image('png', 1, 4097), 'cover.png'),
        };
        $exception = $this->validation(fn () => app(ProjectCoverImages::class)->prepare($invalid));
        $this->assertArrayHasKey('cover', $exception->errors());
    }

    public function test_exact_two_megabyte_content_is_accepted_and_large_metadata_is_removed(): void
    {
        $png = $this->image('png', 80, 50);
        $size = 2048 * 1024;
        $data = "Comment\0".str_repeat('x', $size - strlen($png) - 12 - strlen("Comment\0"));
        $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));
        $bytes = substr($png, 0, -12).$chunk.substr($png, -12);
        $this->assertSame($size, strlen($bytes));
        $prepared = app(ProjectCoverImages::class)->prepare($this->upload($bytes, 'boundary.png'));
        $this->assertLessThan(10000, strlen($prepared['contents']));
        $this->assertSame('image/webp', getimagesizefromstring($prepared['contents'])['mime']);
        $this->assertStringNotContainsString(str_repeat('x', 100), $prepared['contents']);
    }

    public function test_maximum_dimension_boundary_is_supported_and_optimised(): void
    {
        $prepared = app(ProjectCoverImages::class)->prepare($this->upload($this->image('png', 4096, 4096), 'maximum.png'));
        $this->assertSame([1000, 1000], array_slice(getimagesizefromstring($prepared['contents']), 0, 2));
    }

    #[DataProvider('orientations')]
    public function test_exif_orientation_is_normalised_and_metadata_is_stripped(int $orientation, array $expectedCorners): void
    {
        $this->assertTrue(function_exists('exif_read_data'), 'EXIF support is required for this orientation verification.');
        $file = $this->upload($this->jpegWithOrientation($this->quadrants(), $orientation), 'camera.jpeg');
        $metadata = exif_read_data($file->getRealPath(), 'IFD0', true, false);
        $this->assertSame($orientation, $metadata['IFD0']['Orientation']);
        $this->assertSame('Private camera location and author', $metadata['IFD0']['ImageDescription']);
        $prepared = app(ProjectCoverImages::class)->prepare($file);
        $normalised = imagecreatefromstring($prepared['contents']);
        try {
            $this->assertSame($orientation >= 5 ? [20, 40] : [40, 20], [imagesx($normalised), imagesy($normalised)]);
            $positions = [[2, 2], [imagesx($normalised) - 3, 2], [2, imagesy($normalised) - 3], [imagesx($normalised) - 3, imagesy($normalised) - 3]];
            foreach ($positions as $index => [$x, $y]) {
                $colour = imagecolorsforindex($normalised, imagecolorat($normalised, $x, $y));
                $expected = $this->colours()[$expectedCorners[$index]];
                foreach (['red', 'green', 'blue'] as $channel => $key) {
                    $this->assertEqualsWithDelta($expected[$channel], $colour[$key], 35, 'Orientation '.$orientation.' corner '.$index.' '.$key);
                }
            }
        } finally {
            imagedestroy($normalised);
        }
        $this->assertStringNotContainsString('Private camera location and author', $prepared['contents']);
        $this->assertStringNotContainsString("Exif\0\0", $prepared['contents']);
    }

    public function test_orientation_is_applied_before_the_output_bounding_box(): void
    {
        $jpeg = $this->jpegWithOrientation($this->image('jpg', 2000, 1000), 6);
        $prepared = app(ProjectCoverImages::class)->prepare($this->upload($jpeg, 'portrait.jpg'));
        $this->assertSame([500, 1000], array_slice(getimagesizefromstring($prepared['contents']), 0, 2));
    }

    public function test_appended_untrusted_payload_is_not_copied_to_the_output(): void
    {
        $payload = '<script>Untrusted appended upload data</script>';
        $prepared = app(ProjectCoverImages::class)->prepare($this->upload($this->image('png', 80, 50).$payload, 'cover.png'));
        $this->assertStringNotContainsString($payload, $prepared['contents']);
        $this->assertSame('image/webp', getimagesizefromstring($prepared['contents'])['mime']);
    }

    public function test_insufficient_memory_is_rejected_with_an_actionable_error_without_changing_php_settings(): void
    {
        $processor = new class extends ProjectCoverImages
        {
            protected function availableMemoryBytes(): int
            {
                return 4 * 1024 * 1024;
            }
        };
        $limit = ini_get('memory_limit');
        $exception = $this->validation(fn () => $processor->prepare($this->upload($this->image('png', 80, 50), 'cover.png')));
        $this->assertStringContainsString('Choose a smaller image', $exception->errors()['cover'][0]);
        $this->assertSame($limit, ini_get('memory_limit'));
    }

    public function test_missing_processing_support_is_reported_as_validation_feedback(): void
    {
        $processor = new class extends ProjectCoverImages
        {
            protected function supportsProcessing(): bool
            {
                return false;
            }
        };
        $exception = $this->validation(fn () => $processor->prepare($this->upload($this->image('png', 80, 50), 'cover.png')));
        $this->assertStringContainsString('processing is unavailable', $exception->errors()['cover'][0]);
    }

    public function test_decoder_failures_restore_the_previous_php_error_handler(): void
    {
        $seen = [];
        set_error_handler(function ($level, $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });
        try {
            $jpeg = $this->image('jpg', 80, 50);
            $this->validation(fn () => app(ProjectCoverImages::class)->prepare($this->upload(substr($jpeg, 0, -20), 'broken.jpg')));
            trigger_error('project cover handler restored', E_USER_NOTICE);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(['project cover handler restored'], $seen);
    }

    private function validation(callable $operation): ValidationException
    {
        try {
            $operation();
            $this->fail('Expected cover validation failure.');
        } catch (ValidationException $exception) {
            return $exception;
        }
    }

    private function upload(string $contents, string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function image(string $format, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        try {
            imagefill($image, 0, 0, imagecolorallocate($image, 10, 180, 170));

            return $this->encode($image, $format);
        } finally {
            imagedestroy($image);
        }
    }

    private function encode(GdImage $image, string $format): string
    {
        ob_start();
        try {
            match ($format) {
                'jpg' => imagejpeg($image, null, 100),
                'png' => imagepng($image),
                'webp' => imagewebp($image, null, 90),
                'gif' => imagegif($image),
            };

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function quadrants(): string
    {
        $image = imagecreatetruecolor(40, 20);
        try {
            $corners = [[0, 0, 19, 9], [20, 0, 39, 9], [0, 10, 19, 19], [20, 10, 39, 19]];
            foreach (array_values($this->colours()) as $index => $channels) {
                imagefilledrectangle($image, ...[...$corners[$index], imagecolorallocate($image, ...$channels)]);
            }

            return $this->encode($image, 'jpg');
        } finally {
            imagedestroy($image);
        }
    }

    private function colours(): array
    {
        return ['red' => [240, 20, 20], 'green' => [20, 220, 20], 'blue' => [20, 20, 240], 'yellow' => [230, 230, 20]];
    }

    private function jpegWithOrientation(string $jpeg, int $orientation): string
    {
        $description = "Private camera location and author\0";
        $tiff = 'II'.pack('vVv', 42, 8, 2)
            .pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0"
            .pack('vvVV', 0x010E, 2, strlen($description), 38)
            .pack('V', 0).$description;
        $exif = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    }

    public static function formats(): array
    {
        return [['jpg', 'jpg'], ['jpg', 'jpeg'], ['png', 'png'], ['webp', 'webp'], ['jpg', 'JPG'], ['png', 'PNG']];
    }

    public static function resizeCases(): array
    {
        return [[3200, 1600, 1600, 800], [1000, 3000, 333, 1000], [1600, 1000, 1600, 1000], [4096, 1, 1600, 1], [1, 4096, 1, 1000]];
    }

    public static function invalidFiles(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'string', 'array', 'SVG', 'GIF', 'text named PNG', 'fake JPEG',
            'PNG named JPEG', 'JPEG named PNG', 'PNG named WebP', 'unsupported extension', 'truncated PNG',
            'truncated JPEG', 'corrupt PNG checksum', 'reported size', 'actual size', 'width', 'height']);
    }

    public static function orientations(): array
    {
        return [
            [1, ['red', 'green', 'blue', 'yellow']], [2, ['green', 'red', 'yellow', 'blue']],
            [3, ['yellow', 'blue', 'green', 'red']], [4, ['blue', 'yellow', 'red', 'green']],
            [5, ['red', 'blue', 'green', 'yellow']], [6, ['blue', 'red', 'yellow', 'green']],
            [7, ['yellow', 'green', 'blue', 'red']], [8, ['green', 'yellow', 'red', 'blue']],
        ];
    }
}
