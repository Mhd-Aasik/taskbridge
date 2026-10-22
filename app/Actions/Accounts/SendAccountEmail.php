<?php

namespace App\Actions\Accounts;

use App\Support\AccountAccess;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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
            Password::sendResetLink(['email' => $email]);
        } catch (TransportExceptionInterface) {
            // Never log transport payloads: they may contain reset tokens/credentials.
        }
    }

    public function verification(): void
    {
        $user = AccountAccess::account();
        $key = 'account-verification:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw ValidationException::withMessages(['verification' => 'Please wait a minute before requesting another verification email.']);
        }
        RateLimiter::hit($key, 60);
        try {
            $user->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface) {
            throw ValidationException::withMessages(['verification' => 'The verification email could not be sent. Please try again shortly.']);
        }
    }
}
