<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\RegisterByEmail;
use App\Http\Controllers\Controller;
use App\Models\PendingRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

class RegistrationSetupController extends Controller
{
    public function show(string $registration)
    {
        $pending = PendingRegistration::whereKey($registration)->whereNull('consumed_at')->where('expires_at', '>', now())->firstOrFail();

        return response()->view('auth.registration-setup', ['registration' => $pending])->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $registration, RegisterByEmail $action)
    {
        try {
            $action->complete($registration, $request->only(['email', 'setup_token', 'password', 'password_confirmation', 'name', 'role']));
        } catch (ValidationException $error) {
            return response()->view('auth.registration-setup', [
                'registration' => PendingRegistration::findOrFail($registration),
                'setupToken' => is_string($request->input('setup_token')) ? $request->input('setup_token') : '',
                'errors' => (new ViewErrorBag)->put('default', new MessageBag($error->errors())),
            ], 422)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        }

        return redirect()->route('login')->with('status', 'Your email is verified and your password is set. You can now log in.');
    }
}
