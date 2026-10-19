<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function client(Request $request): View
    {
        return view('dashboards.client', ['user' => $request->user()]);
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
