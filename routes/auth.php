<?php

use App\Actions\Accounts\SendAccountEmail;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\RegistrationSetupController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Actions\Logout;
use App\Support\AccountAccess;
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

});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', function () {
        $account = AccountAccess::verificationAccount();

        return $account->hasVerifiedEmail() ? redirect()->route('dashboard') : response()->view('auth.verification-notice');
    })->name('verification.notice');
    Route::post('verify-email', function (SendAccountEmail $action) {
        $account = AccountAccess::verificationAccount();
        if ($account->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }
        $action->verification();

        return redirect()->route('verification.notice')->with('status', 'verification-link-sent');
    })->middleware('throttle:6,1')->name('verification.send');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'auth.confirm-password')
        ->name('password.confirm');
});

Route::post('logout', Logout::class)
    ->name('logout');
