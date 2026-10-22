<?php

namespace App\Support;

use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ProjectPayment
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
        // Payment access is dedicated and status-independent; work mutation policies stay unchanged.
        $user = $users->first();
        abort_unless($user, 403);
        AccountAccess::ensure($user);
        $project = $projects->firstOrFail();
        $assignment = $assignments->firstOrFail();
        Gate::forUser($user)->authorize('viewAny', [PaymentReport::class, $assignment, $project]);

        return [$user, $project, $assignment];
    }
}
