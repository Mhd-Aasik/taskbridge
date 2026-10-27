<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\VerifyExistingEmail;
use App\Http\Controllers\Controller;
use App\Models\EmailVerificationToken;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

class ExistingEmailVerificationController extends Controller
{
    public function notice()
    {
        return response()->view('auth.verification-notice');
    }

    public function send(Request $request, VerifyExistingEmail $action)
    {
        $values = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $action->request($values['email'], (string) $request->ip());

        return redirect()->route('verification.notice')->with('status', VerifyExistingEmail::STATUS);
    }

    public function show(string $verification)
    {
        $record = EmailVerificationToken::whereKey($verification)->whereNull('consumed_at')->where('expires_at', '>', now())->firstOrFail();

        return response()->view('auth.existing-verification', ['verification' => $record])->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request, string $verification, VerifyExistingEmail $action)
    {
        try {
            $action->complete($verification, $request->only(['email', 'token', 'password', 'password_confirmation', 'role', 'user_id']));
        } catch (ValidationException $error) {
            return response()->view('auth.existing-verification', [
                'verification' => EmailVerificationToken::findOrFail($verification),
                'token' => is_string($request->input('token')) ? $request->input('token') : '',
                'errors' => (new ViewErrorBag)->put('default', new MessageBag($error->errors())),
            ], 422)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        }

        return redirect()->route('login')->with('status', 'Your email is verified. Sign in with your existing password.');
    }
}
