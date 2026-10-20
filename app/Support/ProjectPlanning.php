<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ProjectPlanning
{
    /** @return array{User, Project, ProjectAssignment} */
    public static function context(int $projectId, bool $lock = false): array
    {
        $users = User::query()->whereKey(Auth::id());
        $projects = Project::query()->whereKey($projectId);
        $assignments = ProjectAssignment::query()->where('project_id', $projectId);
        if ($lock) {
            $users->lockForUpdate();
            $projects->lockForUpdate();
            $assignments->lockForUpdate();
        }
        // Keep the account -> project -> assignment order used by existing actions.
        $user = $users->first();
        abort_unless($user, 403);
        $project = $projects->firstOrFail();
        $assignment = $assignments->firstOrFail();
        Gate::forUser($user)->authorize('viewPlan', [$assignment, $project]);

        return [$user, $project, $assignment];
    }
}
