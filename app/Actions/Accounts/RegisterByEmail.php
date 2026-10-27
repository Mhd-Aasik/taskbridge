<?php

namespace App\Actions\Accounts;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\CompleteRegistration;
use App\Support\AccountEmailLinks;
use App\Support\AccountEmailTransport;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterByEmail
{
    public const STATUS = 'If this address can register, a setup link has been sent. Existing accounts should log in or use password recovery.';

    public static function email(string $email): string
    {
        return Str::lower(Str::trim($email));
    }

    public function begin(array $input, string $ip): void
    {
        $input['email'] = is_string($input['email'] ?? null) ? self::email($input['email']) : ($input['email'] ?? '');
        $input['name'] = is_string($input['name'] ?? null) ? Str::trim($input['name']) : ($input['name'] ?? '');
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\p{C}]/u'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([User::ROLE_CLIENT, User::ROLE_FREELANCER])],
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'],
        ])->validate();
        $this->deliver($validated['email'], $ip, $validated);
    }

    public function resend(string $email, string $ip): void
    {
        $email = self::email($email);
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();
        $this->deliver($email, $ip);
    }

    private function deliver(string $email, string $ip, ?array $identity = null): void
    {
        $keys = ['registration-ip:'.hash('sha256', $ip) => [5, 60], 'registration-email:'.hash('sha256', $email) => [1, 60]];
        foreach ($keys as $key => [$limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return;
            }
        }
        foreach ($keys as $key => [, $seconds]) {
            RateLimiter::hit($key, $seconds);
        }
        try {
            DB::transaction(function () use ($email, $identity): void {
                $pending = PendingRegistration::where('email', $email)->lockForUpdate()->first();
                if (User::whereRaw('LOWER(email) = ?', [$email])->exists() || (! $pending && ! $identity)) {
                    return;
                }
                $pending ??= new PendingRegistration(['id' => (string) Str::uuid(), 'email' => $email]);
                if ($identity) {
                    $pending->name = $identity['name'];
                    $pending->role = $identity['role'];
                }
                if (! in_array($pending->role, [User::ROLE_CLIENT, User::ROLE_FREELANCER], true)) {
                    return;
                }
                $token = Str::random(64);
                $pending->token_hash = hash('sha256', $token);
                $pending->expires_at = now()->addMinutes(config('auth.registration.expire', 60));
                $pending->consumed_at = null;
                $pending->saveOrFail();
                $url = app(AccountEmailLinks::class)->setup($pending, $token);
                try {
                    AccountEmailTransport::ensureSafe();
                    Notification::route('mail', $email)->notify(new CompleteRegistration($url));
                } catch (Throwable) {
                    // Roll back rotation/identity changes, keeping any previously delivered link.
                    // Never report transport exceptions: they may contain credentials or raw links.
                    throw ValidationException::withMessages(['email' => 'The setup email could not be sent. Please try again shortly.']);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // A competing request/account won. Never overwrite an existing user.
        }
    }

    public function complete(string $id, #[\SensitiveParameter] array $input): User
    {
        $input['email'] = is_string($input['email'] ?? null) ? self::email($input['email']) : ($input['email'] ?? '');
        $values = Validator::make($input, [
            'email' => ['required', 'email', 'max:255'],
            'setup_token' => ['required', 'string', 'size:64', 'alpha_num:ascii'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(), function ($attribute, $value, $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
            'name' => ['prohibited'], 'role' => ['prohibited'],
        ])->validate();
        try {
            $user = DB::transaction(function () use ($id, $values): User {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    // Acquire the SQLite writer lock before reading the identity;
                    // no state changes, and every bound predicate below still applies.
                    DB::table('pending_registrations')->where('id', $id)->update(['id' => DB::raw('id')]);
                }
                $pending = PendingRegistration::whereKey($id)->lockForUpdate()->first();
                if (! $pending || ! in_array($pending->role, [User::ROLE_CLIENT, User::ROLE_FREELANCER], true)) {
                    $this->invalid();
                }
                // Conditional write obtains the database writer/row lock before user creation.
                // The claim, unique user insert and token consumption share one transaction.
                $claimed = PendingRegistration::whereKey($id)->where('email', $values['email'])
                    ->where('name', $pending->name)->where('role', $pending->role)
                    ->where('token_hash', hash('sha256', $values['setup_token']))
                    ->whereNull('consumed_at')->where('expires_at', '>', now())
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);
                if ($claimed !== 1 || User::whereRaw('LOWER(email) = ?', [$values['email']])->exists()) {
                    $this->invalid();
                }
                $user = new User;
                $user->name = $pending->name;
                $user->email = $pending->email;
                $user->role = $pending->role;
                $user->password = Hash::make($values['password']);
                $user->email_verified_at = now();
                $user->saveOrFail();

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->invalid();
        }
        event(new Registered($user)); // Already verified: Laravel's listener sends no second email.

        return $user;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['email' => 'This setup link is invalid, expired or already used. Request a new setup email.']);
    }
}
