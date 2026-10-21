<?php

namespace App\Actions\Notifications;

use App\Models\InAppNotification;
use App\Models\User;
use App\Support\NotificationDestinations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ManageNotifications
{
    public static function account(bool $lock = false): User
    {
        $query = User::query()->whereKey(Auth::id());
        if ($lock) {
            $query->lockForUpdate();
        }
        $user = $query->first();
        abort_unless($user, 403);

        return $user;
    }

    public function setRead(string $id, bool $read): InAppNotification
    {
        return DB::transaction(function () use ($id, $read): InAppNotification {
            $user = self::account(lock: true);
            $notification = $user->notifications()->lockForUpdate()->findOrFail($id);
            Gate::forUser($user)->authorize('update', $notification);
            $this->changeReadState($notification, $read);

            return $notification;
        }, 3);
    }

    /** @return array{url: string, available: bool} */
    public function open(string $id): array
    {
        return DB::transaction(function () use ($id): array {
            $user = self::account(lock: true);
            $notification = $user->notifications()->lockForUpdate()->findOrFail($id);
            Gate::forUser($user)->authorize('update', $notification);
            $url = NotificationDestinations::forNotification($user, $notification);
            if ($url === null) {
                return ['url' => route('notifications.index'), 'available' => false];
            }
            $this->changeReadState($notification, true);

            return ['url' => $url, 'available' => true];
        }, 3);
    }

    /** Capture once on explicit preparation, not every dropdown render or polling request. */
    public function captureUnread(): array
    {
        return DB::transaction(function (): array {
            $user = self::account(lock: true);
            $ids = $user->unreadNotifications()->reorder()->orderBy('id')->pluck('id')->all();
            $token = (string) Str::uuid();
            if (! Cache::put('notification-read-set:'.$token, ['user' => $user->id, 'ids' => $ids], now()->addMinutes(20))) {
                throw new RuntimeException('The unread set could not be captured.');
            }

            return ['token' => $token, 'count' => count($ids)];
        });
    }

    public function markCapturedRead(string $token): int
    {
        return DB::transaction(function () use ($token): int {
            $user = self::account(lock: true);
            $set = Str::isUuid($token) ? Cache::get('notification-read-set:'.$token) : null;
            if (! is_array($set) || ($set['user'] ?? null) !== $user->id || ! is_array($set['ids'] ?? null)) {
                throw ValidationException::withMessages(['notifications' => 'This unread selection is invalid or expired. Capture it again before confirming.']);
            }
            $updated = 0;
            $now = now('UTC');
            foreach (array_chunk($set['ids'], 500) as $ids) {
                $updated += $user->unreadNotifications()->whereKey($ids)->update(['read_at' => $now, 'updated_at' => $now]);
            }

            return $updated;
        }, 3);
    }

    private function changeReadState(InAppNotification $notification, bool $read): void
    {
        if (($notification->read_at !== null) === $read) {
            return;
        }
        $notification->read_at = $read ? now('UTC') : null;
        if (! $notification->save()) {
            throw new RuntimeException('The notification state could not be saved.');
        }
    }
}
