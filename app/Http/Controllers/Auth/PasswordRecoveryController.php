<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\ChangeAccountPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    public function show()
    {
        return response()->view('auth.password-recovery')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, ChangeAccountPassword $action)
    {
        try {
            $values = $request->validate([
                'token' => ['required', 'string', 'size:64', 'alpha_num:ascii'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', 'confirmed', Rules\Password::defaults(), function ($attribute, $value, $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                }],
            ]);
            $status = $action->reset($values);
            if ($status !== Password::PasswordReset) {
                throw ValidationException::withMessages(['email' => __($status)]);
            }
        } catch (ValidationException $error) {
            return response()->view('auth.password-recovery', [
                'token' => is_string($request->input('token')) ? $request->input('token') : '',
                'email' => is_string($request->input('email')) ? $request->input('email') : '',
                'errors' => (new ViewErrorBag)->put('default', new MessageBag($error->errors())),
            ], 422)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        }

        return redirect()->route('login')->with('status', __($status));
    }
}
