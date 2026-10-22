<?php

namespace App\Actions\Accounts;

use App\Models\User;
use App\Services\AccountSessions;
use App\Support\AccountAccess;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class ChangeAccountPassword
{
    /** The caller validates password strength/confirmation; the locked account rechecks the secret. */
    public function update(#[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): User
    {
        return DB::transaction(function () use ($currentPassword, $newPassword): User {
            $account = AccountAccess::account(lock: true);
            if (! Hash::check($currentPassword, $account->password)) {
                throw ValidationException::withMessages(['current_password' => __('auth.password')]);
            }
            $this->persist($account, $newPassword);

            return $account;
        }, 3);
    }

    /** Lock before broker validation, password change and token consumption, on the configured database. */
    public function reset(#[\SensitiveParameter] array $credentials): string
    {
        $credentials = Arr::only($credentials, ['email', 'password', 'password_confirmation', 'token']);
        $broker = Password::broker();
        $candidate = $broker->getUser(Arr::only($credentials, ['email']));
        if (! $candidate instanceof User) {
            return Password::INVALID_USER;
        }

        return $candidate->getConnection()->transaction(function () use ($candidate, $broker, $credentials): string {
            $account = User::on($candidate->getConnectionName())->lockForUpdate()->find($candidate->id);
            if (! $account || $account->email !== $candidate->email) {
                return Password::INVALID_TOKEN;
            }

            // Recovery remains available for suspended accounts. It never clears their status.
            return $broker->reset($credentials, function (User $resolved, string $password) use ($account): void {
                if ($resolved->id !== $account->id) {
                    throw new LogicException('Password recovery resolved a different account.');
                }
                $this->persist($account, $password);
                event(new PasswordReset($account));
            });
        }, 3);
    }

    private function persist(User $account, #[\SensitiveParameter] string $password): void
    {
        $account->password = Hash::make($password);
        $account->remember_token = Str::random(60);
        $account->access_version++;
        if (! $account->save()) {
            throw new RuntimeException('The password could not be saved.');
        }
        app(AccountSessions::class)->revoke($account);
    }
}
