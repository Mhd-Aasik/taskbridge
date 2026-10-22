<?php

namespace App\Actions\Projects;

use App\Enums\ProjectActivityEvent;
use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectActivities;
use App\Support\AccountAccess;
use App\Support\ProjectRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PublishProject
{
    public function publish(User $actor, int $projectId, bool $confirmed): bool
    {
        AccountAccess::ensure($actor);

        return DB::transaction(function () use ($actor, $projectId, $confirmed): bool {
            $user = User::query()->lockForUpdate()->find($actor->getKey());
            abort_unless($user, 403);
            AccountAccess::ensure($user);
            $project = Project::query()->lockForUpdate()->findOrFail($projectId);
            Gate::forUser($user)->authorize('view', $project);

            Validator::make(['confirmed' => $confirmed], ['confirmed' => ['accepted']], [
                'confirmed.accepted' => 'Confirm publication before opening this project for applications.',
            ])->validate();

            if ($project->status === ProjectStatus::Open && $project->published_at !== null) {
                return false;
            }
            Gate::forUser($user)->authorize('publish', $project);

            $details = ProjectRules::savedDetails($project);
            Validator::make($details, ProjectRules::rules(), ProjectRules::messages())->validate();
            $category = Category::query()->whereKey($details['category_id'])->where('is_active', true)->lockForUpdate()->first();
            if (! $category) {
                throw ValidationException::withMessages(['category_id' => 'Choose an active project category.']);
            }

            $now = CarbonImmutable::now('UTC');
            $changed = Project::query()->whereKey($project->id)->where('client_id', $user->id)
                ->where('status', ProjectStatus::Draft->value)->whereNull('published_at')
                ->update(['status' => ProjectStatus::Open->value, 'published_at' => $now, 'updated_at' => $now]);

            if ($changed === 0) {
                $project->refresh();
                Gate::forUser($user)->authorize('view', $project);
                abort_unless($project->status === ProjectStatus::Open && $project->published_at !== null, 403);
            }

            if ($changed === 1) {
                app(ProjectActivities::class)->record(ProjectActivityEvent::Published, $project, $user);
            }

            return $changed === 1;
        }, 3);
    }
}
