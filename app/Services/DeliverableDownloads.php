<?php

namespace App\Services;

use App\Models\DeliverableVersion;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliverableDownloads
{
    public function download(DeliverableVersion $version): StreamedResponse
    {
        $files = app(DeliverableFiles::class);
        abort_unless($files->ownsPath($version), 404);
        $disk = Storage::disk('deliverables');
        abort_unless($disk->exists($version->storage_path), 404);
        $extension = strtolower(pathinfo($version->storage_path, PATHINFO_EXTENSION));

        return $disk->download($version->storage_path, $files->safeDownloadName($version->original_filename, $extension), [
            'Content-Type' => $version->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
