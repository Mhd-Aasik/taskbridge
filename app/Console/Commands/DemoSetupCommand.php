<?php

namespace App\Console\Commands;

use App\Support\LocalDemonstration;
use Illuminate\Console\Command;
use Throwable;

class DemoSetupCommand extends Command
{
    protected $signature = 'taskbridge:demo {--client= : Existing Client ID} {--freelancer= : Existing Freelancer ID}';

    protected $description = 'Explicit local-only demonstration setup using existing accounts and real domain actions';

    public function handle(LocalDemonstration $setup): int
    {
        try {
            LocalDemonstration::ensureLocal();
            foreach (['client', 'freelancer'] as $option) {
                if (! ctype_digit((string) $this->option($option)) || (int) $this->option($option) < 1) {
                    $this->error('Supply explicit existing --client and --freelancer IDs. Back up the database and private files first.');

                    return self::FAILURE;
                }
            }
            $manifest = $setup->run((int) $this->option('client'), (int) $this->option('freelancer'));
            $this->info(count($manifest['projects']).' managed demonstration projects are ready. No real payment or external email was sent.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Demonstration setup refused or failed. Review the local log; existing history is not reset.');

            return self::FAILURE;
        }
    }
}
