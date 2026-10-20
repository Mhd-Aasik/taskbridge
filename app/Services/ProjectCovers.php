<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProjectCovers
{
    public static function ownsPath(Project $project, ?string $path): bool
    {
        return $project->getKey() !== null && $path !== null
            && preg_match('#\Aprojects/'.preg_quote((string) $project->getKey(), '#').'/[a-f0-9-]{36}\.webp\z#D', $path) === 1;
    }

    public function deleteOwnedFile(Project $project, ?string $path): void
    {
        if (! self::ownsPath($project, $path)) {
            return;
        }
        try {
            // A delayed cleanup must never delete a file still referenced by a project.
            if (Project::query()->where('cover_path', $path)->exists()) {
                return;
            }
            if (! Storage::disk('project_covers')->delete($path)) {
                report(new RuntimeException('A superseded project cover could not be deleted.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
