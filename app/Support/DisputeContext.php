<?php

namespace App\Support;

use App\Models\DisputeCase;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class DisputeContext
{
    public static function account(): User
    {
        $user = User::query()->find(Auth::id());
        abort_unless($user, 403);

        return $user;
    }

    /** @return array{User, Project, ProjectAssignment} */
    public static function forProject(int $projectId, bool $lock = false): array
    {
        $users = User::query()->whereKey(Auth::id());
        $projects = Project::query()->whereKey($projectId);
        $assignments = ProjectAssignment::query()->where('project_id', $projectId);
        if ($lock) {
            $users->lockForUpdate();
            $projects->lockForUpdate();
            $assignments->lockForUpdate();
        }
        $user = $users->first();
        abort_unless($user, 403);
        $project = $projects->firstOrFail();
        $assignment = $assignments->firstOrFail();
        Gate::forUser($user)->authorize('create', [DisputeCase::class, $assignment, $project]);

        return [$user, $project, $assignment];
    }

    /** @return array{User, Project, ProjectAssignment, DisputeCase} */
    public static function forCase(int $caseId, bool $lock = false): array
    {
        // Initial reads identify the immutable parent; re-resolve every association under locks below.
        $candidate = DisputeCase::query()->findOrFail($caseId);
        $parent = ProjectAssignment::query()->findOrFail($candidate->project_assignment_id);
        $users = User::query()->whereKey(Auth::id());
        $projects = Project::query()->whereKey($parent->project_id);
        $assignments = ProjectAssignment::query()->whereKey($parent->id)->where('project_id', $parent->project_id);
        if ($lock) {
            $users->lockForUpdate();
            $projects->lockForUpdate();
            $assignments->lockForUpdate();
        }
        $user = $users->first();
        abort_unless($user, 403);
        $project = $projects->firstOrFail();
        $assignment = $assignments->firstOrFail();
        $cases = $assignment->disputes()->whereKey($caseId);
        if ($lock) {
            $cases->lockForUpdate();
        }
        $dispute = $cases->firstOrFail();
        Gate::forUser($user)->authorize('view', [$dispute, $assignment, $project]);

        return [$user, $project, $assignment, $dispute];
    }
}
