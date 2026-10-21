<?php

namespace Tests\Feature;

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\ProjectNotifications;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsNotificationEvents;
use Tests\TestCase;

class NotificationDataTest extends TestCase
{
    use BuildsNotificationEvents, RefreshDatabase;

    #[DataProvider('notificationEvents')]
    public function test_events_use_actual_record_keys_and_only_authorized_recipients_with_minimal_private_payload(NotificationEvent $event): void
    {
        [$origin, $actor, $recipients] = $this->notificationEvent($event);
        $this->assertDatabaseCount('notifications', 0);
        User::factory()->create(['role' => User::ROLE_CLIENT]);
        User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $alerts = $this->emitEvent($event, $origin);
        $expected = array_map(fn ($user) => $user->id, $recipients);
        $this->assertEqualsCanonicalizing($expected, array_map(fn ($notification) => $notification->notifiable_id, $alerts));
        $this->assertDatabaseCount('notifications', count($expected));
        foreach ($alerts as $notification) {
            $this->assertInstanceOf(DatabaseNotification::class, $notification);
            $this->assertSame(['record_id' => $origin->id], $notification->data);
            $this->assertSame($event->value.':'.$origin->id, $notification->event_key);
            $this->assertSame($event->label(), $notification->eventLabel());
            $this->assertNotSame($actor->id, $notification->notifiable_id);
            $this->assertNull($notification->read_at);
            $this->assertTrue(Str::isUuid($notification->id));
            $this->assertTrue($notification->notifiable->notifications->contains('id', $notification->id));
        }
    }

    #[DataProvider('notificationEvents')]
    public function test_event_retries_do_not_duplicate_or_rewrite_existing_read_state(NotificationEvent $event): void
    {
        [$origin] = $this->notificationEvent($event);
        $alerts = $this->emitEvent($event, $origin);
        $before = [];
        foreach ($alerts as $notification) {
            $notification->markAsRead();
            $before[$notification->id] = $notification->fresh()->getAttributes();
        }
        $this->travel(1)->minutes();
        $retried = $this->emitEvent($event, $origin);
        foreach ($retried as $notification) {
            $this->assertSame($before[$notification->id], $notification->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('notifications', count($alerts));
    }

    #[DataProvider('notificationEvents')]
    public function test_rolled_back_events_leave_no_alerts(NotificationEvent $event): void
    {
        [$origin] = $this->notificationEvent($event);
        try {
            DB::transaction(function () use ($event, $origin): void {
                app(ProjectNotifications::class)->record($event, $origin);
                throw new RuntimeException('Abort event');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort event', $exception->getMessage());
        }
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_recipient_event_pair_has_database_unique_constraint(): void
    {
        [$record] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $record)[0];
        $this->expectException(QueryException::class);
        DB::table('notifications')->insert(array_merge($notification->getAttributes(), ['id' => (string) Str::uuid()]));
    }

    public function test_notification_data_is_guarded_and_native_read_scopes_work(): void
    {
        [$record, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $record)[0];
        $this->assertSame(1, $recipient->unreadNotifications()->count());
        $notification->markAsRead();
        $this->assertSame(1, $recipient->readNotifications()->count());
        $notification->markAsUnread();
        $this->assertSame(1, $recipient->unreadNotifications()->count());
        $this->expectException(MassAssignmentException::class);
        $notification->fill(['notifiable_id' => 9999, 'data' => ['url' => 'https://example.com']]);
    }

    public function test_existing_notification_payload_cannot_be_rewritten(): void
    {
        [$record] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $record)[0];
        $notification->data = ['record_id' => 9999];
        $this->expectException(LogicException::class);
        $notification->save();
    }

    public function test_mismatched_record_or_decision_is_refused(): void
    {
        [$record] = $this->notificationEvent(NotificationEvent::VersionChangesRequested);
        $this->expectException(LogicException::class);
        $this->emitEvent(NotificationEvent::VersionApproved, $record);
    }
}
