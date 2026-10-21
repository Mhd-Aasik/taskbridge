<?php

namespace App\Support;

use App\Enums\AdminAction;
use App\Enums\ProjectStatus;
use App\Models\AdminActionLog;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryManagement
{
    public static function admin(bool $lock = false): User
    {
        $query = User::query()->whereKey(Auth::id());
        if ($lock) {
            $query->lockForUpdate();
        }
        $user = $query->first();
        abort_unless($user, 403);
        Gate::forUser($user)->authorize('viewAny', Category::class);

        return $user;
    }

    public static function details(array $input): array
    {
        $details = ['name' => $input['name'] ?? null, 'description' => $input['description'] ?? null];
        foreach ($details as $field => $value) {
            if (is_string($value)) {
                $details[$field] = Str::trim($value);
            }
        }
        if ($details['description'] === '') {
            $details['description'] = null;
        }

        return Validator::make($details, [
            // The existing VARCHAR(255) safely supports this stricter application limit.
            'name' => ['bail', 'required', 'string', 'max:100', 'regex:/[\p{L}\p{N}]/u'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], ['name.regex' => 'Use a meaningful category name containing letters or numbers.'])->validate();
    }

    public static function withImpact(Builder $query): Builder
    {
        return $query->withCount(['projects', 'projects as open_projects_count' => fn (Builder $projects) => $projects->where('status', ProjectStatus::Open->value),
            'projects as discoverable_projects_count' => fn (Builder $projects) => $projects->discoverable(),
            'projects as assigned_projects_count' => fn (Builder $projects) => $projects->whereHas('assignment')]);
    }

    public static function impact(Category $category): array
    {
        $category = self::withImpact($category->newQuery())->findOrFail($category->id);

        return ['total' => (int) $category->projects_count, 'open' => (int) $category->open_projects_count,
            'discoverable' => (int) $category->discoverable_projects_count, 'assigned' => (int) $category->assigned_projects_count];
    }

    /** Separate optimistic revisions prevent one kind of form from overwriting the other. */
    public static function version(Category $category, bool $activation = false): string
    {
        $actions = $activation ? [AdminAction::CategoryActivated, AdminAction::CategoryDeactivated] : [AdminAction::CategoryCreated, AdminAction::CategoryUpdated];
        $last = AdminActionLog::on($category->getConnectionName())->where('subject_type', $category->getMorphClass())->where('subject_id', $category->id)->whereIn('action', array_map(fn ($action) => $action->value, $actions))->max('id');
        $values = $category->only($activation ? ['name', 'is_active'] : ['name', 'description']);

        return hash('sha256', json_encode([$category->id, $values, $last], JSON_THROW_ON_ERROR));
    }
}
