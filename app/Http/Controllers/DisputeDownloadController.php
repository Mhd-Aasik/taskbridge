<?php

namespace App\Http\Controllers;

use App\Models\DeliverableVersion;
use App\Services\DeliverableDownloads;
use App\Support\DisputeContext;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisputeDownloadController extends Controller
{
    public function __invoke(int $dispute, int $version): StreamedResponse
    {
        [$user, $project, $assignment, $case] = DisputeContext::forCase($dispute);
        Gate::forUser($user)->authorize('viewAdmin', [$case, $assignment, $project]);
        $record = DeliverableVersion::query()->whereHas('deliverable', fn ($query) => $query->where('project_assignment_id', $assignment->id))->findOrFail($version);
        Gate::forUser($user)->authorize('download', [$case, $record, $assignment, $project]);

        return app(DeliverableDownloads::class)->download($record);
    }
}
