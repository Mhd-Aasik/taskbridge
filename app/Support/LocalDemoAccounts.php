<?php

namespace App\Support;

use App\Actions\Accounts\ChangeAccountPassword;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use RuntimeException;

class LocalDemoAccounts
{
    /** Operator-supplied secrets stay outside command arguments, source and output. */
    public function update(#[\SensitiveParameter] array $input): array
    {
        LocalDemonstration::ensureLocal();

        return DB::transaction(function () use ($input): array {
            $ids = [$input['client']['id'] ?? null, $input['freelancer']['id'] ?? null];
            if (count(array_unique($ids)) !== 2) {
                throw new RuntimeException('Explicit distinct account IDs are required.');
            }
            $accounts = User::whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $details = [];
            foreach (['client', 'freelancer'] as $role) {
                $record = $input[$role];
                $user = $accounts->get($record['id']);
                if (! $user || $user->role !== $role || ! $user->isActive() || ! in_array($user->email, [$record['expected_email'] ?? null, $record['email'] ?? null], true)) {
                    throw new RuntimeException('Account identity, active state or saved role does not match the explicit selection.');
                }
                $details[$role] = Validator::make($record, [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                    'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
                ])->validate();
            }
            if ($details['client']['email'] === $details['freelancer']['email']) {
                throw new RuntimeException('Demonstration emails must be distinct.');
            }
            $result = [];
            foreach (['client', 'freelancer'] as $role) {
                $user = $accounts->get($input[$role]['id']);
                $values = $details[$role];
                $before = $user->only(['id', 'name', 'email', 'role']);
                // An email change is a credential checkpoint even when the chosen
                // local-demo password already matches the previous password.
                if ($user->email !== $values['email'] || ! Hash::check($values['password'], $user->password)) {
                    $token = Password::broker()->createToken($user);
                    $status = app(ChangeAccountPassword::class)->reset(['email' => $user->email, 'password' => $values['password'], 'password_confirmation' => $values['password'], 'token' => $token]);
                    if ($status !== Password::PASSWORD_RESET) {
                        throw new RuntimeException('Supported password reset refused the selected account.');
                    }
                    $user->refresh();
                }
                $user->fill(['name' => $values['name'], 'email' => $values['email']]);
                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }
                if ($user->isDirty()) {
                    $user->saveOrFail();
                }
                $result[$role] = ['before' => $before, 'after' => $user->only(['id', 'name', 'email', 'role'])];
            }

            return $result;
        }, 3);
    }
}
