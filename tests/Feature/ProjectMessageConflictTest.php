<?php

namespace Tests\Feature;

use App\Actions\Messages\ReadProjectMessages;
use App\Actions\Messages\SendProjectMessage;
use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use App\Models\ProjectReadCursor;
use App\Support\MessagePresentation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProjectMessageConflictTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('matchingWinner')]
    public function test_simulated_send_conflict_returns_only_matching_original_message(bool $matching): void
    {
        // Inject a durable winner after rollback; this is not parallel MySQL testing.
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $token = (string) Str::uuid();
        ProjectMessage::creating(function () use ($assignment, $token, $matching): never {
            DB::afterRollBack(function () use ($assignment, $token, $matching): void {
                DB::table('project_messages')->insert(['project_assignment_id' => $assignment->id, 'sender_id' => $assignment->project->client_id, 'body' => $matching ? 'Original' : 'Competing different content', 'sent_at' => '2026-10-28 12:00:00', 'submission_id' => $token, 'created_at' => now(), 'updated_at' => now()]);
            });
            throw $this->conflict('project_messages.submission_id');
        });
        try {
            $message = app(SendProjectMessage::class)->send($assignment->project_id, ['body' => 'Original'], $token);
            $this->assertTrue($matching);
            $this->assertSame('2026-10-28 12:00:00', $message->sent_at->format('Y-m-d H:i:s'));
        } catch (ValidationException $exception) {
            $this->assertFalse($matching);
            $this->assertArrayHasKey('message_conflict', $exception->errors());
        }
        $this->assertDatabaseCount('project_messages', 1);
    }

    public function test_simulated_competing_cursor_insert_advances_durable_winner_monotonically(): void
    {
        $first = ProjectMessage::factory()->create();
        $next = ProjectMessage::factory()->create(['project_assignment_id' => $first->project_assignment_id]);
        $assignment = $first->assignment;
        $viewer = $assignment->freelancer;
        $this->actingAs($viewer);
        $proof = MessagePresentation::issue($viewer, $assignment, [$first->id, $next->id]);
        ProjectReadCursor::creating(function () use ($assignment, $viewer, $first): never {
            DB::afterRollBack(function () use ($assignment, $viewer, $first): void {
                DB::table('project_read_cursors')->insert(['project_assignment_id' => $assignment->id, 'user_id' => $viewer->id, 'last_read_message_id' => $first->id, 'created_at' => now(), 'updated_at' => now()]);
            });
            throw $this->conflict('project_read_cursors.project_assignment_id, project_read_cursors.user_id');
        });
        $cursor = app(ReadProjectMessages::class)->mark($assignment->project_id, $proof);
        $this->assertSame($next->id, $cursor->last_read_message_id);
        $this->assertDatabaseCount('project_read_cursors', 1);
    }

    #[DataProvider('operations')]
    public function test_known_conflict_without_durable_winner_propagates(string $operation): void
    {
        [$assignment, $proof, $class, $column] = $this->context($operation);
        $failure = $this->conflict($column);
        $class::creating(function () use ($failure): never {
            throw $failure;
        });
        $this->expectExceptionObject($failure);
        $this->executeOperation($operation, $assignment, $proof);
    }

    #[DataProvider('operations')]
    public function test_unrelated_unique_conflicts_are_not_swallowed(string $operation): void
    {
        [$assignment, $proof, $class] = $this->context($operation);
        $failure = $this->conflict($operation === 'send' ? 'project_messages.id' : 'project_read_cursors.id');
        $class::creating(function () use ($failure): never {
            throw $failure;
        });
        $this->expectExceptionObject($failure);
        $this->executeOperation($operation, $assignment, $proof);
    }

    #[DataProvider('operations')]
    public function test_save_veto_and_transaction_rollback_cannot_partially_persist(string $operation): void
    {
        [$assignment, $proof, $class] = $this->context($operation);
        $class::creating(fn () => false);
        try {
            $this->executeOperation($operation, $assignment, $proof);
            $this->fail('A veto cannot report success.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not be saved', $exception->getMessage());
        }
        $this->assertDatabaseCount('project_messages', $operation === 'send' ? 0 : 1);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_outer_rollback_preserves_previous_cursor(): void
    {
        $first = ProjectMessage::factory()->create();
        $next = ProjectMessage::factory()->create(['project_assignment_id' => $first->project_assignment_id]);
        $assignment = $first->assignment;
        $viewer = $assignment->freelancer;
        $cursor = ProjectReadCursor::factory()->create(['last_read_message_id' => $first->id]);
        $before = $cursor->fresh()->getAttributes();
        $this->actingAs($viewer);
        try {
            DB::transaction(function () use ($assignment, $viewer, $first, $next): void {
                app(ReadProjectMessages::class)->mark($assignment->project_id, MessagePresentation::issue($viewer, $assignment, [$first->id, $next->id]));
                throw new RuntimeException('Abort cursor update');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort cursor update', $exception->getMessage());
        }
        $this->assertSame($before, $cursor->fresh()->getAttributes());
    }

    private function context(string $operation): array
    {
        $assignment = ProjectAssignment::factory()->create();
        if ($operation === 'send') {
            $this->actingAs($assignment->project->client);

            return [$assignment, (string) Str::uuid(), ProjectMessage::class, 'project_messages.submission_id'];
        }
        $message = ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->freelancer);

        return [$assignment, MessagePresentation::issue($assignment->freelancer, $assignment, [$message->id]), ProjectReadCursor::class, 'project_read_cursors.project_assignment_id, project_read_cursors.user_id'];
    }

    private function executeOperation(string $operation, ProjectAssignment $assignment, string $proof): mixed
    {
        return $operation === 'send' ? app(SendProjectMessage::class)->send($assignment->project_id, ['body' => 'Original'], $proof) : app(ReadProjectMessages::class)->mark($assignment->project_id, $proof);
    }

    private function conflict(string $column): UniqueConstraintViolationException
    {
        $pdo = new PDOException('UNIQUE constraint failed: '.$column);
        $pdo->errorInfo = ['23000', 19, $pdo->getMessage()];

        return new UniqueConstraintViolationException('sqlite', 'insert simulated conflict', [], $pdo);
    }

    public static function matchingWinner(): array
    {
        return [[true], [false]];
    }

    public static function operations(): array
    {
        return [['send'], ['read']];
    }
}
