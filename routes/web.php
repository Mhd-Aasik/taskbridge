<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('client/dashboard', 'dashboard')
        ->middleware('role:'.User::ROLE_CLIENT)
        ->name('client.dashboard');

    Route::view('freelancer/dashboard', 'dashboard')
        ->middleware('role:'.User::ROLE_FREELANCER)
        ->name('freelancer.dashboard');

    Route::view('admin/dashboard', 'dashboard')
        ->middleware('role:'.User::ROLE_ADMIN)
        ->name('admin.dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
