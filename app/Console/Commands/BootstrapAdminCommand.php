<?php

namespace App\Console\Commands;

use App\Actions\Accounts\BootstrapAdmin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class BootstrapAdminCommand extends Command
{
    protected $signature = 'taskbridge:bootstrap-admin';

    protected $description = 'Interactively create the first Admin, only when no Admin exists';

    protected function hasInteractiveTerminal(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    public function handle(BootstrapAdmin $bootstrap): int
    {
        if (! $this->input->isInteractive() || ! $this->hasInteractiveTerminal()) {
            $this->error('Bootstrap requires an interactive terminal; no account was created.');

            return self::FAILURE;
        }
        if (User::query()->where('role', User::ROLE_ADMIN)->exists()) {
            $this->error('An Admin account already exists. Bootstrap is unavailable.');

            return self::FAILURE;
        }
        try {
            $this->line('Use at least 12 characters with upper/lowercase letters, a number and a symbol (maximum 72 bytes).');
            $input = [
                'name' => $this->ask('Name'),
                'email' => $this->ask('Email'),
                'password' => $this->secret('Password', false),
                'password_confirmation' => $this->secret('Confirm password', false),
            ];
            $values = $bootstrap->validate($input);
            $this->table(['Name', 'Email', 'Role', 'Email verification'], [[$values['name'], $values['email'], 'Admin', 'Unverified (optional)']]);
            if (! $this->confirm('Create this first Admin account?', false)) {
                $this->info('Cancelled. No account was created.');

                return self::SUCCESS;
            }
            $bootstrap->create($input);
            $this->info('Admin created. Sign in through the normal login page.');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }
        } catch (Throwable) {
            // Exception payloads may contain credentials/bindings; never print or log them.
            $this->error('Bootstrap could not complete. No account was created; check database availability and try again.');
        }

        return self::FAILURE;
    }
}
