<?php

namespace App\Observers;

use App\Models\Project;
use App\Services\ProjectCovers;
use Illuminate\Support\Facades\DB;

class ProjectObserver
{
    public function deleting(Project $project): void
    {
        $project->cover_path = Project::query()->whereKey($project->getKey())->value('cover_path');
    }

    public function deleted(Project $project): void
    {
        $path = $project->cover_path;
        // Capture a stable identity for delayed cleanup after deletion commits.
        $identity = clone $project;
        DB::afterCommit(fn () => app(ProjectCovers::class)->deleteOwnedFile($identity, $path));
    }
}
