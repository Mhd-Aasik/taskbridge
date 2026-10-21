<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\DisputeCase;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ProjectReputation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function client(Request $request): View
    {
        $user = User::query()->find($request->user()->id);
        abort_unless($user, 403);
        Gate::forUser($user)->authorize('viewAny', Project::class);

        return view('dashboards.client', [
            'user' => $user,
            'projectCount' => $user->projects()->count(),
            'reputation' => ProjectReputation::forUsers([$user->id], User::ROLE_CLIENT)[$user->id],
            'draftCount' => $user->projects()->where('status', ProjectStatus::Draft->value)->count(),
            'openCount' => $user->projects()->where('status', ProjectStatus::Open->value)->count(),
            'assignedProjectCount' => $user->projects()->whereHas('assignment')->count(),
            'completedProjectCount' => $user->projects()->where('status', ProjectStatus::Completed->value)->count(),
            'receivedProposalCount' => Proposal::query()->whereHas('project', fn (Builder $projects) => $projects->where('client_id', $user->id))->count(),
            'recentProjects' => $user->projects()->with(['category', 'assignment.freelancer:id,name', 'assignment.paymentReport.acknowledgment', 'completer:id,name'])->withCount('proposals')->latest('updated_at')->latest('id')->limit(3)->get(),
        ]);
    }

    public function freelancer(Request $request): View
    {
        $user = User::query()->find($request->user()->id);
        abort_unless($user, 403);
        Gate::forUser($user)->authorize('viewAny', ProjectAssignment::class);

        return view('dashboards.freelancer', [
            'user' => $user,
            'assignmentCount' => $user->projectAssignments()->count(),
            'reputation' => ProjectReputation::forUsers([$user->id], User::ROLE_FREELANCER)[$user->id],
            'completedAssignmentCount' => $user->projectAssignments()->whereHas('project', fn ($query) => $query->where('status', ProjectStatus::Completed->value))->count(),
            'recentAssignments' => $user->projectAssignments()->with(['project:id,title,status,cover_path,completed_at,completed_by,completion_note', 'project.completer:id,name', 'paymentReport.acknowledgment'])->orderByDesc('assigned_at')->orderByDesc('id')->limit(3)->get(),
        ]);
    }

    public function admin(Request $request): View
    {
        $user = User::query()->find($request->user()->id);
        abort_unless($user, 403);
        Gate::forUser($user)->authorize('viewAdminAny', DisputeCase::class);

        $counts = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return view('dashboards.admin', [
            'user' => $user,
            'openDisputeCount' => DisputeCase::query()->inState('open')->count(),
            'accountCounts' => [
                'total' => (int) $counts->sum(),
                'clients' => (int) $counts->get(User::ROLE_CLIENT, 0),
                'freelancers' => (int) $counts->get(User::ROLE_FREELANCER, 0),
                'admins' => (int) $counts->get(User::ROLE_ADMIN, 0),
            ],
        ]);
    }
}
