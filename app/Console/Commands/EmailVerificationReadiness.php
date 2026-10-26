<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AccountEmailLinks;
use App\Support\AccountEmailTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EmailVerificationReadiness extends Command
{
    protected $signature = 'taskbridge:verification-readiness {--admin-email= : Confirmed existing Admin identity} {--enforced : Also require enforcement to be enabled}';

    protected $description = 'Read-only deployment gates; never sends email or modifies accounts';

    public function handle(): int
    {
        try {
            $email = strtolower(trim((string) $this->option('admin-email')));
            $this->gate(filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Provide the confirmed Admin email.');
            $admin = User::where('email', $email)->where('role', User::ROLE_ADMIN)->sole();
            $this->gate($admin->isActive() && $admin->hasVerifiedEmail(), 'The confirmed active Admin must complete real email verification first.');
            $this->gate(! config('app.debug'), 'APP_DEBUG must be false.');
            app(AccountEmailLinks::class)->generator();
            $this->gate(str_starts_with(config('app.url'), 'https://'), 'A trusted HTTPS APP_URL is required.');
            AccountEmailTransport::ensureSafe();
            $this->gate(config('mail.default') === 'smtp', 'SMTP must be configured; capture/log transports do not satisfy this gate.');
            $smtp = config('mail.mailers.smtp');
            $this->gate(! empty($smtp['host']) && ! empty($smtp['username']) && ! empty($smtp['password']) && empty($smtp['url']), 'Provide private individual SMTP credentials without MAIL_URL overrides.');
            $this->gate(($smtp['require_tls'] ?? false) === true, 'SMTP must require TLS.');
            $this->gate(filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false, 'Configure the owned sender.');
            $this->gate(Schema::hasTable('pending_registrations'), 'Apply the additive pending-registration migration first.');
            $this->gate(! $this->option('enforced') || config('auth.require_verified_email'), 'The final enforcement switch is not enabled.');
            $this->info('Configuration and verified-Admin gates passed. No mail sent; no data changed.');
            $this->warn('Separately review recorded external delivery, CI, backup/rollback and public checks before activation.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Never print configuration, credentials, transport errors or SQL bindings.
            $this->error($error instanceof \DomainException ? $error->getMessage() : 'Readiness unavailable: check Admin identity, database and private configuration.');

            return self::FAILURE;
        }
    }

    private function gate(bool $valid, string $message): void
    {
        if (! $valid) {
            throw new \DomainException($message);
        }
    }
}
