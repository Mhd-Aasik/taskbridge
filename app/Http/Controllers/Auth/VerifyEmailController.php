<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\AccountAccess;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $request->session()->pull('url.intended');
        DB::transaction(function () use ($request): void {
            $account = AccountAccess::account(lock: true);
            abort_unless((string) $account->id === (string) $request->route('id') && hash_equals(sha1($account->getEmailForVerification()), (string) $request->route('hash')), 403);
            if (! $account->hasVerifiedEmail() && $account->markEmailAsVerified()) {
                event(new Verified($account));
            }
        }, 3);

        return redirect()->route('dashboard', ['verified' => 1]);
    }
}
