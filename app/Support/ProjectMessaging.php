<?php

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ProjectMessaging
{
    public static function account(): User
    {
        $user = User::query()->find(Auth::id());
        abort_unless($user, 403);
        AccountAccess::ensure($user);

        return $user;
    }

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
        $user = $users->first();
        abort_unless($user, 403);
        AccountAccess::ensure($user);
        $project = $projects->firstOrFail();
        $assignment = $assignments->firstOrFail();
        Gate::forUser($user)->authorize('viewConversation', [ProjectMessage::class, $assignment, $project]);
        $assignment->setRelation('project', $project);

        return [$user, $project, $assignment];
    }

    /** @return Builder<ProjectAssignment> */
    public static function assignments(User $user): Builder
    {
        Gate::forUser($user)->authorize('viewAny', ProjectMessage::class);

        return ProjectAssignment::query()->whereHas('project', function ($projects) use ($user) {
            $projects->whereIn('status', [ProjectStatus::InProgress, ProjectStatus::Completed, ProjectStatus::Cancelled]);
            if ($user->isClient()) {
                $projects->where('client_id', $user->id);
            }
        })->when($user->isFreelancer(), fn ($query) => $query->where('freelancer_id', $user->id));
    }

    public static function totalUnread(User $user): int
    {
        return ProjectMessage::query()->whereIn('project_assignment_id', self::assignments($user)->select('project_assignments.id'))->unreadFor($user)->count();
    }
}
