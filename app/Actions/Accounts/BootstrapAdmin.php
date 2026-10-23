<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BootstrapAdmin
{
    public function validate(array $input): array
    {
        $input['name'] = Str::trim($input['name'] ?? '');
        $input['email'] = Str::lower(Str::trim($input['email'] ?? ''));

        return Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\p{C}]/u'],
            'email' => ['required', 'email', 'max:255', function ($attribute, $value, $fail): void {
                if (User::query()->whereRaw('LOWER(email) = ?', [$value])->exists()) {
                    $fail('This email address is already in use.');
                }
            }],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(), function ($attribute, $value, $fail): void {
                if (strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
        ])->validate();
    }

    public function create(array $input): User
    {
        if (! app()->runningInConsole()) {
            throw new RuntimeException('Administrator bootstrap is console-only.');
        }
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        // Release must follow the actual commit, never an enclosing transaction.
        if ($connection->transactionLevel() !== 0 && $driver !== 'sqlite') {
            throw new RuntimeException('Bootstrap requires its own transaction.');
        }
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Bootstrap supports MySQL and SQLite only.');
        }
        $lock = 'taskbridge:bootstrap:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 40);
        $held = false;
        try {
            if ($driver === 'mysql') {
                $held = (int) $connection->selectOne('SELECT GET_LOCK(?, 10) acquired', [$lock])->acquired === 1;
                if (! $held) {
                    throw ValidationException::withMessages(['admin' => 'Another bootstrap is running. Try again after it finishes.']);
                }
            }

            return $connection->transaction(function () use ($connection, $driver, $input): User {
                if ($driver === 'sqlite') {
                    // Acquire SQLite's writer reservation before reading the empty set.
                    $connection->update('UPDATE users SET id = id WHERE 1 = 0');
                }
                if (User::query()->where('role', User::ROLE_ADMIN)->lockForUpdate()->first()) {
                    throw ValidationException::withMessages(['admin' => 'An Admin account already exists. Bootstrap is unavailable.']);
                }
                $values = $this->validate($input);
                $user = new User;
                $user->name = $values['name'];
                $user->email = $values['email'];
                $user->password = Hash::make($values['password']);
                $user->role = User::ROLE_ADMIN;
                $user->email_verified_at = null;
                $user->avatar_path = null;
                $user->suspended_at = null;
                $user->suspended_by = null;
                $user->suspension_reason = null;
                $user->access_version = 0;
                $user->remember_token = null;
                if (! $user->save()) {
                    throw new RuntimeException('Administrator creation failed.');
                }

                return $user;
            }, 3);
        } finally {
            if ($held) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) released', [$lock]);
            }
        }
    }
}
