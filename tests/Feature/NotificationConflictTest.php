<?php

namespace Tests\Feature;

use App\Actions\Messages\SendProjectMessage;
use App\Enums\NotificationEvent;
use App\Models\InAppNotification;
use App\Models\ProjectAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsNotificationEvents;
use Tests\TestCase;

class NotificationConflictTest extends TestCase
{
    use BuildsNotificationEvents, RefreshDatabase;

    public function test_simulated_recipient_event_conflict_recovers_the_durable_winner_without_rewriting_it(): void
    {
        // Inject after savepoint rollback; this is not real parallel MySQL execution.
        [$record, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $winnerId = (string) Str::uuid();
        InAppNotification::creating(function () use ($record, $recipient, $winnerId): never {
            DB::afterRollBack(function () use ($record, $recipient, $winnerId): void {
                DB::table('notifications')->insert(['id' => $winnerId, 'type' => NotificationEvent::ProjectMessageSent->value, 'notifiable_type' => $recipient->getMorphClass(), 'notifiable_id' => $recipient->id,
                    'event_key' => NotificationEvent::ProjectMessageSent->value.':'.$record->id, 'data' => json_encode(['record_id' => $record->id]), 'read_at' => '2026-10-29 12:00:00', 'created_at' => '2026-10-29 11:00:00', 'updated_at' => '2026-10-29 12:00:00']);
            });
            throw $this->conflict('notifications.notifiable_type, notifications.notifiable_id, notifications.event_key');
        });
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $record)[0];
        $this->assertSame($winnerId, $notification->id);
        $this->assertSame('2026-10-29 12:00:00', $notification->read_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-29 11:00:00', $notification->created_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('notifications', 1);
    }

    #[DataProvider('conflicts')]
    public function test_missing_winner_and_unrelated_database_conflicts_propagate_and_roll_back_origin(string $column): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $failure = $this->conflict($column);
        InAppNotification::creating(function () use ($failure): never {
            throw $failure;
        });
        try {
            app(SendProjectMessage::class)->send($assignment->project_id, ['body' => 'Rollback this message'], (string) Str::uuid());
            $this->fail('Unrecoverable database failures must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertDatabaseCount('project_messages', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_duplicate_key_with_different_payload_is_refused_without_repairing_history(): void
    {
        [$record] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $record)[0];
        DB::table('notifications')->where('id', $notification->id)->update(['data' => json_encode(['record_id' => 999999])]);
        $this->expectException(LogicException::class);
        $this->emitEvent(NotificationEvent::ProjectMessageSent, $record);
    }

    private function conflict(string $columns): UniqueConstraintViolationException
    {
        $pdo = new PDOException('UNIQUE constraint failed: '.$columns);
        $pdo->errorInfo = ['23000', 19, $pdo->getMessage()];

        return new UniqueConstraintViolationException('sqlite', 'insert simulated notification conflict', [], $pdo);
    }

    public static function conflicts(): array
    {
        return [['notifications.notifiable_type, notifications.notifiable_id, notifications.event_key'], ['notifications.id'], ['unrelated_table.unique_column']];
    }
}
