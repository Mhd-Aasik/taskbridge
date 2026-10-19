<?php

use App\Http\Controllers\AccountPhotoController;
use App\Http\Controllers\DashboardController;
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
});

require __DIR__.'/auth.php';
