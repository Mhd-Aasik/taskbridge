<?php

use App\Http\Controllers\AccountPhotoController;
use App\Http\Controllers\ClientProposalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliverableDownloadController;
use App\Http\Controllers\DisputeDownloadController;
use App\Http\Controllers\FreelancerAssignmentController;
use App\Http\Controllers\FreelancerDiscoveryController;
use App\Http\Controllers\FreelancerProfilePhotoController;
use App\Http\Controllers\ProjectActivityController;
use App\Http\Controllers\ProjectCoverController;
use App\Http\Controllers\ProjectDiscoveryController;
use App\Http\Controllers\ProjectPublicationController;
use App\Http\Controllers\ProposalAcceptanceController;
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
    Volt::route('notifications', 'notifications.index')->name('notifications.index');
    Route::get('settings/appearance', function () {
        return to_route('dashboard');
    })->name('settings.appearance');
});

Volt::route('freelancer/profile', 'freelancer.profile')
    ->middleware(['auth', 'role:'.User::ROLE_FREELANCER])
    ->name('freelancer.profile.edit');

Route::get('freelancer/profile/preview', [FreelancerDiscoveryController::class, 'preview'])->middleware(['auth', 'role:freelancer'])->name('freelancer.profile.preview');
Route::middleware(['auth', 'role:client'])->prefix('client/freelancers')->name('client.freelancers.')->group(function () {
    Route::get('/', [FreelancerDiscoveryController::class, 'index'])->name('index');
    Route::get('{profile}', [FreelancerDiscoveryController::class, 'show'])->whereNumber('profile')->name('show');
    Route::get('{profile}/photo', FreelancerProfilePhotoController::class)->whereNumber('profile')->name('photo');
});

Volt::route('admin/categories', 'admin.categories')->middleware(['auth', 'role:admin'])->name('admin.categories.index');
Volt::route('admin/accounts', 'admin.accounts.index')->middleware(['auth', 'role:admin'])->name('admin.accounts.index');
Volt::route('admin/accounts/{account}', 'admin.accounts.show')->middleware(['auth', 'role:admin'])->whereNumber('account')->name('admin.accounts.show');

Volt::route('projects/{project}/plan', 'projects.plan')->middleware('auth')->whereNumber('project')->name('projects.plan');
Route::get('projects/{project}/activity', ProjectActivityController::class)->middleware(['auth', 'role:client,freelancer'])->whereNumber('project')->name('projects.activity');
Volt::route('projects/{project}/completion', 'projects.completion')->middleware('auth')->whereNumber('project')->name('projects.completion');
Volt::route('projects/{project}/feedback', 'projects.feedback')->middleware(['auth', 'role:client,freelancer'])->whereNumber('project')->name('projects.feedback');
Volt::route('projects/{project}/payment', 'projects.payment')->middleware('auth')->whereNumber('project')->name('projects.payment');
Volt::route('messages', 'messages.index')->middleware(['auth', 'role:client,freelancer'])->name('messages.index');
Volt::route('projects/{project}/messages', 'messages.show')->middleware(['auth', 'role:client,freelancer'])->whereNumber('project')->name('projects.messages');
Volt::route('projects/{project}/disputes/create', 'disputes.create')->middleware(['auth', 'role:client,freelancer'])->whereNumber('project')->name('projects.disputes.create');
Volt::route('disputes', 'disputes.index')->middleware(['auth', 'role:client,freelancer'])->name('disputes.index');
Volt::route('disputes/{dispute}', 'disputes.show')->middleware(['auth', 'role:client,freelancer'])->whereNumber('dispute')->name('disputes.show');
Volt::route('admin/disputes', 'disputes.index')->defaults('administration', true)->middleware(['auth', 'role:admin'])->name('admin.disputes.index');
Volt::route('admin/disputes/{dispute}', 'disputes.show')->defaults('administration', true)->middleware(['auth', 'role:admin'])->whereNumber('dispute')->name('admin.disputes.show');
Route::get('admin/disputes/{dispute}/versions/{version}/download', DisputeDownloadController::class)->middleware(['auth', 'role:admin'])->whereNumber(['dispute', 'version'])->name('admin.disputes.download');
Route::get('projects/{project}/cover', ProjectCoverController::class)->middleware('auth')->whereNumber('project')->name('projects.cover');
Route::middleware('auth')->prefix('projects/{project}/deliverables')->whereNumber('project')->name('projects.deliverables.')->group(function () {
    Volt::route('/', 'projects.deliverables.index')->name('index');
    Volt::route('{deliverable}', 'projects.deliverables.show')->whereNumber('deliverable')->name('show');
    Route::get('{deliverable}/versions/{version}/download', DeliverableDownloadController::class)
        ->whereNumber(['deliverable', 'version'])->name('download');
});

Route::middleware(['auth', 'role:'.User::ROLE_CLIENT])->prefix('client/projects')->name('client.projects.')->group(function () {
    Volt::route('/', 'client.projects.index')->name('index');
    Volt::route('create', 'client.projects.editor')->name('create');
    Volt::route('{project}/edit', 'client.projects.editor')->name('edit');
    Volt::route('{project}', 'client.projects.show')->name('show');
    Route::post('{project}/publish', ProjectPublicationController::class)->name('publish');
    Route::get('{project}/proposals', [ClientProposalController::class, 'index'])->whereNumber('project')->name('proposals.index');
    Route::get('{project}/proposals/{proposal}', [ClientProposalController::class, 'show'])->whereNumber(['project', 'proposal'])->scopeBindings()->name('proposals.show');
    Route::post('{project}/proposals/{proposal}/accept', ProposalAcceptanceController::class)->whereNumber(['project', 'proposal'])->scopeBindings()->name('proposals.accept');
});

Route::middleware(['auth', 'role:'.User::ROLE_FREELANCER])->prefix('freelancer/projects')->name('freelancer.projects.')->group(function () {
    Route::get('/', [ProjectDiscoveryController::class, 'index'])->name('index');
    Route::get('{project}', [ProjectDiscoveryController::class, 'show'])->whereNumber('project')->name('show');
});

Route::middleware(['auth', 'role:'.User::ROLE_FREELANCER])->group(function () {
    Route::get('freelancer/assigned-projects', [FreelancerAssignmentController::class, 'index'])->name('freelancer.assignments.index');
    Route::get('freelancer/assigned-projects/{assignment}', [FreelancerAssignmentController::class, 'show'])->whereNumber('assignment')->name('freelancer.assignments.show');
    Volt::route('freelancer/projects/{project}/proposal', 'freelancer.proposals.create')->whereNumber('project')->name('freelancer.proposals.create');
    Volt::route('freelancer/proposals', 'freelancer.proposals.index')->name('freelancer.proposals.index');
    Volt::route('freelancer/proposals/{proposal}', 'freelancer.proposals.show')->whereNumber('proposal')->name('freelancer.proposals.show');
});

require __DIR__.'/auth.php';
