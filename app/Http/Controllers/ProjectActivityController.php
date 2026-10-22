<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\AccountAccess;
use App\Support\ProjectActivityHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProjectActivityController extends Controller
{
    public function __invoke(Request $request, Project $project)
    {
        $user = AccountAccess::account();
        $project = Project::query()->findOrFail($project->id);
        $assignment = $project->assignment()->first();
        $query = ProjectActivityHistory::query($user, $project, $assignment);
        $input = Validator::make($request->query(), ['page' => ['nullable', 'integer', 'min:1', 'max:10000']])->validate();
        $activities = $query->with('actor:id,name')->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(15, ['*'], 'page', (int) ($input['page'] ?? 1));

        return response()->view('projects.activity', compact('user', 'project', 'assignment', 'activities'))
            ->header('Cache-Control', 'private, no-store');
    }
}
