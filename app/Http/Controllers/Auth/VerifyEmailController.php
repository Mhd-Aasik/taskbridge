<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Legacy signed GET is read-only; ownership is completed by the guest token POST.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $request->session()->pull('url.intended');

        return redirect()->route('verification.notice')->with('status', 'Request a new verification email and confirm it before signing in. Opening this old link has not verified an account.');
    }
}
