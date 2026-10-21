<?php

namespace App\Http\Controllers;

use App\Services\DeliverableDownloads;
use App\Support\ProjectPlanning;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliverableDownloadController extends Controller
{
    public function __invoke(int $project, int $deliverable, int $version): StreamedResponse
    {
        [$user, $record, $assignment] = ProjectPlanning::context($project);
        $deliverable = $assignment->deliverables()->findOrFail($deliverable);
        $version = $deliverable->versions()->findOrFail($version);
        Gate::forUser($user)->authorize('download', [$version, $deliverable, $assignment, $record]);

        return app(DeliverableDownloads::class)->download($version);
    }
}
