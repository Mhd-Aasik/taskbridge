<?php

use App\Http\Controllers\AccountPhotoController;
use App\Http\Controllers\ClientProposalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectDiscoveryController;
use App\Http\Controllers\ProjectPublicationController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('dashboard', function (Request $request) {
    $dashboard = match ($request->user()->role) {
        User::ROLE_CLIENT => 'client.dashboard',
        User::ROLE_FREELANCER => 'freelancer.dashboard',
        User::ROLE_ADMIN => 'admin.dashboard',
        default => abort(403),
    };

    return to_route($dashboard);
})
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('client/dashboard', [DashboardController::class, 'client'])
        ->middleware('role:'.User::ROLE_CLIENT)
        ->name('client.dashboard');

    Route::get('freelancer/dashboard', [DashboardController::class, 'freelancer'])
        ->middleware('role:'.User::ROLE_FREELANCER)
        ->name('freelancer.dashboard');

    Route::get('admin/dashboard', [DashboardController::class, 'admin'])
        ->middleware('role:'.User::ROLE_ADMIN)
        ->name('admin.dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::get('account/{user}/photo', AccountPhotoController::class)->name('account.photo');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Route::get('settings/appearance', function () {
        return to_route('dashboard');
    })->name('settings.appearance');
});

Volt::route('freelancer/profile', 'freelancer.profile')
    ->middleware(['auth', 'role:'.User::ROLE_FREELANCER])
    ->name('freelancer.profile.edit');

Route::middleware(['auth', 'role:'.User::ROLE_CLIENT])->prefix('client/projects')->name('client.projects.')->group(function () {
    Volt::route('/', 'client.projects.index')->name('index');
    Volt::route('create', 'client.projects.editor')->name('create');
    Volt::route('{project}/edit', 'client.projects.editor')->name('edit');
    Volt::route('{project}', 'client.projects.show')->name('show');
    Route::post('{project}/publish', ProjectPublicationController::class)->name('publish');
    Route::get('{project}/proposals', [ClientProposalController::class, 'index'])->whereNumber('project')->name('proposals.index');
    Route::get('{project}/proposals/{proposal}', [ClientProposalController::class, 'show'])->whereNumber(['project', 'proposal'])->scopeBindings()->name('proposals.show');
});

Route::middleware(['auth', 'role:'.User::ROLE_FREELANCER])->prefix('freelancer/projects')->name('freelancer.projects.')->group(function () {
    Route::get('/', [ProjectDiscoveryController::class, 'index'])->name('index');
    Route::get('{project}', [ProjectDiscoveryController::class, 'show'])->whereNumber('project')->name('show');
});

Route::middleware(['auth', 'role:'.User::ROLE_FREELANCER])->group(function () {
    Volt::route('freelancer/projects/{project}/proposal', 'freelancer.proposals.create')->whereNumber('project')->name('freelancer.proposals.create');
    Volt::route('freelancer/proposals', 'freelancer.proposals.index')->name('freelancer.proposals.index');
    Volt::route('freelancer/proposals/{proposal}', 'freelancer.proposals.show')->whereNumber('proposal')->name('freelancer.proposals.show');
});

require __DIR__.'/auth.php';
