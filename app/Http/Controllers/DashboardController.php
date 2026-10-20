<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function client(Request $request): View
    {
        Gate::authorize('viewAny', Project::class);
        $user = $request->user();

        return view('dashboards.client', [
            'user' => $user,
            'projectCount' => $user->projects()->count(),
            'draftCount' => $user->projects()->where('status', ProjectStatus::Draft->value)->count(),
            'openCount' => $user->projects()->where('status', ProjectStatus::Open->value)->count(),
            'recentProjects' => $user->projects()->with('category')->latest('updated_at')->latest('id')->limit(3)->get(),
        ]);
    }

    public function freelancer(Request $request): View
    {
        return view('dashboards.freelancer', ['user' => $request->user()]);
    }

    public function admin(Request $request): View
    {
        $counts = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return view('dashboards.admin', [
            'user' => $request->user(),
            'accountCounts' => [
                'total' => (int) $counts->sum(),
                'clients' => (int) $counts->get(User::ROLE_CLIENT, 0),
                'freelancers' => (int) $counts->get(User::ROLE_FREELANCER, 0),
                'admins' => (int) $counts->get(User::ROLE_ADMIN, 0),
            ],
        ]);
    }
}
