<?php

namespace App\Actions\Accounts;

use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Notifications\VerifyExistingEmail as Notification;
use App\Support\AccountEmailLinks;
use App\Support\AccountEmailTransport;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerifyExistingEmail
{
    public const STATUS = 'If an active account needs verification, an email will be sent. Check your inbox, or request a new link shortly.';

    public function request(string $email, string $ip): void
    {
        $email = RegisterByEmail::email($email);
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();
        $keys = ['verify-ip:'.hash('sha256', $ip) => 5, 'verify-email:'.hash('sha256', $email) => 1];
        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return;
            }
        }
        foreach ($keys as $key => $limit) {
            RateLimiter::hit($key, 60);
        }
        try {
            DB::transaction(function () use ($email): void {
                $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                if (! $user || ! $user->isActive() || $user->hasVerifiedEmail()) {
                    return;
                }
                AccountEmailTransport::ensureSafe();
                $record = EmailVerificationToken::where('user_id', $user->id)->lockForUpdate()->first();
                $record ??= new EmailVerificationToken(['id' => (string) Str::uuid(), 'user_id' => $user->id]);
                $token = Str::random(64);
                $record->forceFill(['email' => $user->email, 'access_version' => $user->access_version, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(60), 'consumed_at' => null])->saveOrFail();
                $user->notify(new Notification(app(AccountEmailLinks::class)->existingVerification($record, $token)));
            }, 3);
        } catch (Throwable) {
            // Generic public response and rollback preserve any previously delivered link.
            // No transport exception, recipient, password or bearer link is logged.
        }
    }

    public function complete(string $id, #[\SensitiveParameter] array $input): void
    {
        $values = Validator::make($input, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'size:64', 'alpha_num:ascii'],
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'], 'role' => ['prohibited'], 'user_id' => ['prohibited'],
        ])->validate();
        $userId = EmailVerificationToken::whereKey($id)->value('user_id');
        DB::transaction(function () use ($id, $userId, $values): void {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('email_verification_tokens')->where('id', $id)->update(['id' => DB::raw('id')]);
            }
            // Both issuance and completion lock user before token to avoid lock inversion.
            $user = User::whereKey($userId)->lockForUpdate()->first();
            if (! $user || ! $user->isActive() || $user->hasVerifiedEmail() || $user->email !== $values['email']) {
                $this->invalid();
            }
            $claimed = EmailVerificationToken::whereKey($id)->where('user_id', $user->id)
                ->where('email', $user->email)->where('access_version', $user->access_version)
                ->where('token_hash', hash('sha256', $values['token']))->whereNull('consumed_at')->where('expires_at', '>', now())
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                $this->invalid();
            }
            if (! $user->markEmailAsVerified()) {
                $this->invalid();
            }
            DB::afterCommit(fn () => event(new Verified($user)));
        }, 3);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['email' => 'This verification link is invalid, expired or already used. Request a new verification email.']);
    }
}
