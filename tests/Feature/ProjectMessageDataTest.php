<?php

namespace Tests\Feature;

use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use App\Models\ProjectReadCursor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectMessageDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_relationships_and_utc_casts(): void
    {
        $message = ProjectMessage::factory()->create(['sent_at' => CarbonImmutable::parse('2026-10-28 00:15:00', 'Asia/Colombo')]);
        $cursor = ProjectReadCursor::factory()->create(['last_read_message_id' => $message->id]);
        $this->assertSame('2026-10-27 18:45:00', $message->fresh()->sent_at->format('Y-m-d H:i:s'));
        $this->assertTrue($message->assignment->messages->sole()->is($message));
        $this->assertTrue($message->sender->projectMessages->sole()->is($message));
        $this->assertTrue($cursor->assignment->is($message->assignment));
        $this->assertTrue($cursor->lastReadMessage->is($message));
        $this->assertTrue($cursor->user->projectReadCursors->sole()->is($cursor));
        $this->assertTrue($cursor->assignment->readCursors->sole()->is($cursor));
        $this->assertSame($message->id, $cursor->fresh()->last_read_message_id);
        $this->assertArrayNotHasKey('submission_id', $message->toArray());
    }

    #[DataProvider('models')]
    public function test_all_fields_are_guarded(string $class): void
    {
        $this->expectException(MassAssignmentException::class);
        (new $class)->fill(['project_assignment_id' => 999, 'sender_id' => 999, 'body' => 'Forged', 'user_id' => 999, 'last_read_message_id' => 999]);
    }

    #[DataProvider('immutableOperations')]
    public function test_message_history_is_immutable(string $operation): void
    {
        $message = ProjectMessage::factory()->create();
        $this->expectException(LogicException::class);
        if ($operation === 'delete') {
            $message->delete();
        } else {
            $message->body = 'Changed';
            $message->save();
        }
    }

    #[DataProvider('cursorMutations')]
    public function test_cursor_identity_and_monotonic_model_protections(string $mutation): void
    {
        $first = ProjectMessage::factory()->create();
        $last = ProjectMessage::factory()->create(['project_assignment_id' => $first->project_assignment_id]);
        $cursor = ProjectReadCursor::factory()->create(['last_read_message_id' => $last->id]);
        $this->expectException(LogicException::class);
        if ($mutation === 'delete') {
            $cursor->delete();
        } else {
            $cursor->{$mutation} = $mutation === 'last_read_message_id' ? $first->id : 99999;
            $cursor->save();
        }
    }

    #[DataProvider('constraints')]
    public function test_database_constraints_preserve_history_and_cursor_scope(string $constraint): void
    {
        // Detached legacy actors ensure deletion fails on these new foreign keys, not older project/proposal links.
        $message = ProjectMessage::factory()->create(['sender_id' => User::factory()->create()->id]);
        $cursor = ProjectReadCursor::factory()->create(['last_read_message_id' => $message->id, 'user_id' => User::factory()->create()->id]);
        $this->expectException(QueryException::class);
        match ($constraint) {
            'message_uuid' => DB::table('project_messages')->insert(array_merge($message->getAttributes(), ['id' => 9999])),
            'assignment_user' => DB::table('project_read_cursors')->insert(array_merge($cursor->getAttributes(), ['id' => 9999])),
            'mismatched_message' => DB::table('project_read_cursors')->insert(array_merge($cursor->getAttributes(), ['id' => 9999, 'project_assignment_id' => ProjectAssignment::factory()->create()->id])),
            'assignment_delete' => DB::table('project_assignments')->where('id', $message->project_assignment_id)->delete(),
            'sender_delete' => DB::table('users')->where('id', $message->sender_id)->delete(),
            'reader_delete' => DB::table('users')->where('id', $cursor->user_id)->delete(),
            'message_delete' => DB::table('project_messages')->where('id', $message->id)->delete(),
            'unknown_assignment' => DB::table('project_messages')->insert(array_merge($message->getAttributes(), ['id' => 9999, 'project_assignment_id' => 9999, 'submission_id' => (string) Str::uuid()])),
        };
    }

    public static function models(): array
    {
        return [[ProjectMessage::class], [ProjectReadCursor::class]];
    }

    public static function immutableOperations(): array
    {
        return [['update'], ['delete']];
    }

    public static function cursorMutations(): array
    {
        return [['project_assignment_id'], ['user_id'], ['last_read_message_id'], ['delete']];
    }

    public static function constraints(): array
    {
        return array_map(fn ($constraint) => [$constraint], ['message_uuid', 'assignment_user', 'mismatched_message', 'assignment_delete', 'sender_delete', 'reader_delete', 'message_delete', 'unknown_assignment']);
    }
}
