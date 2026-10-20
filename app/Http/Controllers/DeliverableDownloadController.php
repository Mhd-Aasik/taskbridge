<?php

namespace App\Http\Controllers;

use App\Services\DeliverableFiles;
use App\Support\ProjectPlanning;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliverableDownloadController extends Controller
{
    public function __invoke(int $project, int $deliverable, int $version): StreamedResponse
    {
        [$user, $record, $assignment] = ProjectPlanning::context($project);
        $deliverable = $assignment->deliverables()->findOrFail($deliverable);
        $version = $deliverable->versions()->findOrFail($version);
        Gate::forUser($user)->authorize('download', [$version, $deliverable, $assignment, $record]);
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
