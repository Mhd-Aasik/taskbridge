<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectCovers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ProjectCoverController extends Controller
{
    public function __invoke(Request $request, Project $project): Response
    {
        Gate::authorize('viewCover', $project);
        abort_unless(ProjectCovers::ownsPath($project, $project->cover_path), 404);
        $disk = Storage::disk('project_covers');
        abort_unless($disk->exists($project->cover_path), 404);
        abort_unless($disk->mimeType($project->cover_path) === 'image/webp', 404);

        $response = $disk->response($project->cover_path, 'project-cover.webp', [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-cache, max-age=0, must-revalidate',
            'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
        $response->setEtag(hash('sha256', $project->cover_path));
        $response->setLastModified((new \DateTimeImmutable)->setTimestamp($disk->lastModified($project->cover_path)));
        // Always authorize first, even when returning a conditional response.
        $version = substr(hash('sha256', $project->cover_path), 0, 16);
        if ($request->query('v', $version) === $version) {
            $response->isNotModified($request);
        }

        return $response;
    }
}
