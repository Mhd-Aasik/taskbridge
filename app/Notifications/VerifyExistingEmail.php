<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;

class VerifyExistingEmail extends VerifyEmail
{
    public function __construct(#[\SensitiveParameter] public readonly string $verificationLink) {}

    protected function verificationUrl($notifiable)
    {
        return $this->verificationLink;
    }
}
