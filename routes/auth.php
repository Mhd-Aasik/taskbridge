<?php

use App\Http\Controllers\Auth\ExistingEmailVerificationController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\RegistrationSetupController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('login', 'auth.login')
        ->name('login');

    Volt::route('register', 'auth.register')
        ->name('register');

    Volt::route('forgot-password', 'auth.forgot-password')
        ->name('password.request');

    Route::get('reset-password', [PasswordRecoveryController::class, 'show'])->name('password.reset');
    Route::post('reset-password', [PasswordRecoveryController::class, 'store'])->middleware('throttle:6,1')->name('password.update');
    Route::get('register/setup/{registration}', [RegistrationSetupController::class, 'show'])->whereUuid('registration')->name('registration.setup');
    Route::post('register/setup/{registration}', [RegistrationSetupController::class, 'store'])->whereUuid('registration')->middleware('throttle:6,1')->name('registration.complete');

    Route::get('verify-email', [ExistingEmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('verify-email', [ExistingEmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('verify-email/confirm/{verification}', [ExistingEmailVerificationController::class, 'show'])->whereUuid('verification')->name('verification.confirm');
    Route::post('verify-email/confirm/{verification}', [ExistingEmailVerificationController::class, 'complete'])->whereUuid('verification')->middleware('throttle:6,1')->name('verification.complete');

});

Route::middleware('auth')->group(function () {

    Volt::route('confirm-password', 'auth.confirm-password')
        ->name('password.confirm');
});

// Legacy signed links never mutate verification on GET. Users request a new POST flow.
Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
    ->whereNumber('id')->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

Route::post('logout', Logout::class)
    ->name('logout');
