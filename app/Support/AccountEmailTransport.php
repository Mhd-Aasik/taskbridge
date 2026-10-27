<?php

namespace App\Support;

use RuntimeException;

class AccountEmailTransport
{
    public static function ensureSafe(): void
    {
        // Account messages contain bearer secrets. Never send them to a log mailer,
        // including an indirect failover; production must have real SMTP configured.
        $mailer = config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        if (! in_array($transport, app()->environment('production') ? ['smtp'] : ['smtp', 'array'], true)) {
            throw new RuntimeException('Configure SMTP or an isolated array capture for account mail.');
        }
    }
}
