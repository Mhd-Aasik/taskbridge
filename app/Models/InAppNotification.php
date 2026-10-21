<?php

namespace App\Models;

use App\Enums\NotificationEvent;
use Illuminate\Notifications\DatabaseNotification;
use LogicException;

class InAppNotification extends DatabaseNotification
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['data' => 'array', 'notifiable_id' => 'integer', 'read_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $notification): void {
            if ($notification->isDirty(['id', 'type', 'notifiable_type', 'notifiable_id', 'event_key', 'data', 'created_at'])) {
                throw new LogicException('Notification identity and event data cannot be rewritten.');
            }
        });
    }

    public function event(): ?NotificationEvent
    {
        return NotificationEvent::tryFrom($this->type);
    }

    public function eventLabel(): string
    {
        return $this->event()?->label() ?? 'This notification is no longer available.';
    }
}
