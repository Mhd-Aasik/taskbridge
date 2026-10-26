<?php

namespace App\Notifications;

use App\Support\AccountEmailLinks;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompleteRegistration extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $setupUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(AccountEmailLinks::class)->message('setup', $this->setupUrl);
    }
}
