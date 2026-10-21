<?php

namespace App\Http\Controllers;

use App\Models\ProjectAssignment;
use App\Models\User;
use App\Support\TaskProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class FreelancerAssignmentController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->account($request);
        Gate::forUser($user)->authorize('viewAny', ProjectAssignment::class);
        $page = Validator::make($request->query(), ['page' => ['nullable', 'integer', 'min:1', 'max:10000']])->validate();

        return view('freelancer.assignments.index', [
            'assignments' => $user->projectAssignments()->with(['project:id,title,client_id,status,cover_path', 'project.client:id,name'])
                ->orderByDesc('assigned_at')->orderByDesc('id')->paginate(10, ['*'], 'page', (int) ($page['page'] ?? 1)),
        ]);
    }

    public function show(Request $request, ProjectAssignment $assignment): View
    {
        $user = $this->account($request);
        $assignment = ProjectAssignment::query()->findOrFail($assignment->id);
        Gate::forUser($user)->authorize('view', $assignment);

        return view('freelancer.assignments.show', [
            'assignment' => $assignment->load(['project', 'project.client:id,name', 'project.category:id,name', 'project.completer:id,name']),
            'taskProgress' => TaskProgress::forAssignment($assignment),
        ]);
    }

    private function account(Request $request): User
    {
        $user = User::query()->find($request->user()->id);
        abort_unless($user, 403);

        return $user;
    }
}
