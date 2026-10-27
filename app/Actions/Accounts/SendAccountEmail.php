<?php

namespace App\Actions\Accounts;

use App\Support\AccountAccess;
use App\Support\AccountEmailTransport;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SendAccountEmail
{
    public const RESET_STATUS = 'A reset link will be sent if the account exists.';

    public function reset(string $email, string $ip): void
    {
        // Limit before lookup. Known, unknown, throttled and failed deliveries share one response.
        $key = 'account-reset:'.hash('sha256', $ip);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }
        RateLimiter::hit($key, 60);
        try {
            AccountEmailTransport::ensureSafe();
            Password::sendResetLink(['email' => $email]);
        } catch (Throwable) {
            // Never log transport payloads: they may contain reset tokens/credentials.
        }
    }

    public function verification(): void
    {
        $user = AccountAccess::verificationAccount();
        app(VerifyExistingEmail::class)->request($user->email, (string) request()->ip());
    }
}
