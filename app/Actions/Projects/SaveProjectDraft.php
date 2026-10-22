<?php

namespace App\Actions\Projects;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectCoverImages;
use App\Services\ProjectCovers;
use App\Support\AccountAccess;
use App\Support\ProjectRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SaveProjectDraft
{
    public function save(?int $projectId, array $input, mixed $cover = null, bool $removeCover = false): Project
    {
        $newPath = null;
        $identity = null;
        $covers = app(ProjectCovers::class);

        try {
            $saved = DB::transaction(function () use ($projectId, $input, $cover, $removeCover, $covers, &$newPath, &$identity): Project {
                // Shared user -> project -> category order coordinates with publication.
                $user = User::query()->lockForUpdate()->find(Auth::id());
                abort_unless($user, 403);
                AccountAccess::ensure($user);
                $project = $projectId === null ? null : Project::query()->lockForUpdate()->findOrFail($projectId);
                Gate::forUser($user)->authorize($project ? 'update' : 'create', $project ?? Project::class);

                $details = array_intersect_key($input, ProjectRules::rules());
                foreach (['title', 'description'] as $field) {
                    if (isset($details[$field]) && is_string($details[$field])) {
                        $details[$field] = trim($details[$field]);
                    }
                }
                $details = Validator::make($details, ProjectRules::rules(), ProjectRules::messages())->validate();
                [$whole, $fraction] = array_pad(explode('.', $details['budget'], 2), 2, '');
                $details['budget'] = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
                $category = Category::query()->whereKey($details['category_id'])->where('is_active', true)->lockForUpdate()->first();
                if (! $category) {
                    throw ValidationException::withMessages(['category_id' => 'Choose an active project category.']);
                }
                if ($cover !== null && $removeCover) {
                    throw ValidationException::withMessages(['cover' => 'Choose a replacement image or remove the current cover, then save.']);
                }
                $prepared = $cover === null ? null : app(ProjectCoverImages::class)->prepare($cover);
                if (! $project) {
                    $project = new Project($details);
                    $project->client()->associate($user);
                    if (! $project->saveOrFail()) {
                        throw new RuntimeException('The project draft could not be created.');
                    }
                } else {
                    $project->fill($details);
                }
                $identity = $project;
                $oldPath = $project->cover_path;
                if ($prepared) {
                    $disk = Storage::disk('project_covers');
                    // Retries with the same normalized image preserve its URL and file.
                    $unchanged = ProjectCovers::ownsPath($project, $oldPath) && $disk->exists($oldPath)
                        && hash_equals(hash('sha256', $disk->get($oldPath)), hash('sha256', $prepared['contents']));
                    if (! $unchanged) {
                        $newPath = 'projects/'.$project->id.'/'.Str::uuid().'.webp';
                        DB::afterRollBack(fn () => $covers->deleteOwnedFile($project, $newPath));
                        if (! $disk->put($newPath, $prepared['contents'])) {
                            throw new RuntimeException('The project cover could not be stored.');
                        }
                        $project->cover_path = $newPath;
                    }
                } elseif ($removeCover) {
                    $project->cover_path = null;
                }
                if (! $project->saveOrFail()) {
                    throw new RuntimeException('The project draft could not be saved.');
                }
                if ($oldPath !== $project->cover_path) {
                    DB::afterCommit(fn () => $covers->deleteOwnedFile($project, $oldPath));
                }

                return $project;
            });
        } catch (Throwable $exception) {
            if ($identity && $newPath) {
                $covers->deleteOwnedFile($identity, $newPath);
            }
            throw $exception;
        }

        // Also attach cleanup to an enclosing transaction after our savepoint commits.
        if ($newPath !== null) {
            DB::afterRollBack(fn () => $covers->deleteOwnedFile($saved, $newPath));
        }

        return $saved;
    }
}
