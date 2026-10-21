<?php

namespace App\Actions\Categories;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\Category;
use App\Services\AdminActionLogs;
use App\Support\CategoryManagement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ManageCategory
{
    public function create(array $input, string $actionId): array
    {
        return $this->run(AdminAction::CategoryCreated, null, $input, '', [], $actionId);
    }

    public function edit(int $id, array $input, string $version, string $actionId): array
    {
        return $this->run(AdminAction::CategoryUpdated, $id, $input, $version, [], $actionId);
    }

    public function setActive(int $id, bool $active, string $version, array $impact, string $actionId, bool $confirmed): array
    {
        return $this->run($active ? AdminAction::CategoryActivated : AdminAction::CategoryDeactivated, $id, ['confirmed' => $confirmed], $version, $impact, $actionId);
    }

    private function run(AdminAction $action, ?int $id, array $input, string $version, array $impact, string $actionId): array
    {
        try {
            return $this->persist($action, $id, $input, $version, $impact, $actionId);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            $known = str_contains($message, 'categories_slug_unique') || str_contains($message, 'UNIQUE constraint failed: categories.slug')
                || str_contains($message, 'admin_action_logs_action_id_unique') || str_contains($message, 'UNIQUE constraint failed: admin_action_logs.action_id');
            if (! $known) {
                throw $exception;
            }

            return $this->persist($action, $id, $input, $version, $impact, $actionId, $exception);
        }
    }

    private function persist(AdminAction $action, ?int $id, array $input, string $version, array $impact, string $actionId, ?UniqueConstraintViolationException $conflict = null): array
    {
        return DB::transaction(function () use ($action, $id, $input, $version, $impact, $actionId, $conflict): array {
            $actor = CategoryManagement::admin(lock: true);
            $activation = in_array($action, [AdminAction::CategoryActivated, AdminAction::CategoryDeactivated], true);
            Validator::make(['action_id' => $actionId], ['action_id' => ['required', 'uuid']])->validate();
            if ($activation) {
                Validator::make($input, ['confirmed' => ['accepted']])->validate();
                $details = ['is_active' => $action === AdminAction::CategoryActivated];
            } else {
                $details = CategoryManagement::details($input);
            }
            if ($id !== null) {
                Validator::make(['revision' => $version], ['revision' => ['required', 'regex:/\A[a-f0-9]{64}\z/']])->validate();
            }
            $hash = hash('sha256', json_encode([$action->value, $id, $details, $version, $impact], JSON_THROW_ON_ERROR));
            // Lock before the first snapshot read so MySQL revisions/counts reflect commits
            // that finished while this request waited for the category.
            $category = $id === null ? new Category : Category::query()->lockForUpdate()->findOrFail($id);
            // Receipts are immutable; a plain read avoids unnecessary log locks.
            $receipt = AdminActionLog::query()->where('action_id', $actionId)->first();
            if ($receipt) {
                if ($receipt->actor_id !== $actor->id || $receipt->action !== $action || $receipt->subject_type !== (new Category)->getMorphClass() || ($id !== null && $receipt->subject_id !== $id) || ! hash_equals($receipt->request_hash, $hash)) {
                    throw ValidationException::withMessages(['category' => 'This action identifier already records a different administrative request. Reload the form before continuing.']);
                }

                return ['category' => Category::query()->findOrFail($receipt->subject_id), 'changed' => false, 'replayed' => true];
            }
            Gate::forUser($actor)->authorize($id === null ? 'create' : ($activation ? 'setActive' : 'update'), $id === null ? Category::class : $category);
            if ($action === AdminAction::CategoryCreated) {
                $slug = Str::slug($details['name']);
                if ($slug === '' || strlen($slug) > 255) {
                    throw ValidationException::withMessages(['name' => 'Choose a name that produces a usable category slug with letters or numbers.']);
                }
                if ($conflict) {
                    $message = (string) ($conflict->errorInfo[2] ?? '');
                    if (! str_contains($message, 'categories_slug_unique') && ! str_contains($message, 'UNIQUE constraint failed: categories.slug')) {
                        throw $conflict;
                    }
                }
                if (Category::query()->where('slug', $slug)->exists()) {
                    throw ValidationException::withMessages(['name' => 'A category already uses this normalized name. Choose a more distinct name.']);
                }
                if ($conflict) {
                    throw $conflict;
                }
                $before = [];
                $category->name = $details['name'];
                $category->description = $details['description'];
                $category->slug = $slug;
                $category->is_active = true;
            } else {
                if ($conflict) {
                    throw $conflict;
                }
                $before = $category->only($action->fields(true));
                if ($before === $details) {
                    return ['category' => $category, 'changed' => false, 'replayed' => false];
                }
                if (! hash_equals(CategoryManagement::version($category, $activation), $version)) {
                    throw ValidationException::withMessages(['category' => 'This category changed after the form was opened. Reload its latest details or reopen the activation confirmation before saving.']);
                }
                if ($activation && CategoryManagement::impact($category) !== $impact) {
                    throw ValidationException::withMessages(['category' => 'Associated project counts changed. Refresh the confirmation and review the current impact before continuing.']);
                }
                foreach ($details as $field => $value) {
                    $category->{$field} = $value;
                }
            }
            if (! $category->save()) {
                throw new RuntimeException('The category could not be saved.');
            }
            $after = $category->only($action->fields());
            app(AdminActionLogs::class)->record($actor, $action, $category, $before, $after, $actionId, $hash);

            return ['category' => $category, 'changed' => true, 'replayed' => false];
        }, 3);
    }
}
