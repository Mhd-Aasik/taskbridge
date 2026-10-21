<?php

namespace Tests\Feature;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompletionDataTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('models')]
    public function test_completion_defaults_are_null_and_guarded_and_times_normalize_to_utc(string $class): void
    {
        $record = $class::factory()->create()->fresh();
        foreach (['completed_at', 'completed_by', 'completion_note'] as $field) {
            $this->assertNull($record->{$field});
            $this->assertFalse($record->isFillable($field));
        }
        $this->assertFalse($record->isCompleted());
        $actor = User::factory()->create();
        $record->forceFill(['completed_at' => '2026-10-26 00:15:00+05:30', 'completed_by' => $actor->id, 'completion_note' => 'Outcome'])->save();
        $record->refresh();
        $this->assertTrue($record->isCompleted());
        $this->assertInstanceOf(CarbonImmutable::class, $record->completed_at);
        $this->assertSame('2026-10-25 18:45:00', $record->getRawOriginal('completed_at'));
        $this->assertSame('UTC', $record->completed_at->timezoneName);
        $this->assertTrue($record->completer->is($actor));
        $relation = $class === Project::class ? 'completedProjects' : 'completedMilestones';
        $this->assertTrue($actor->{$relation}->sole()->is($record));
    }

    #[DataProvider('immutableFields')]
    public function test_recorded_completion_metadata_cannot_be_rewritten(string $class, string $field): void
    {
        $actor = User::factory()->create();
        $record = $class::factory()->create(['completed_at' => now(), 'completed_by' => $actor->id, 'completion_note' => 'Original'])->fresh();
        $before = $record->getAttributes();
        try {
            $record->forceFill([$field => null])->save();
            $this->fail('Completion metadata must remain immutable.');
        } catch (LogicException) {
            $this->assertSame($before, $record->fresh()->getAttributes());
        }
    }

    #[DataProvider('models')]
    public function test_completer_foreign_key_restricts_account_deletion(string $class): void
    {
        $actor = User::factory()->create();
        $class::factory()->create(['completed_by' => $actor->id]);
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $actor->id)->delete();
    }

    #[DataProvider('models')]
    public function test_unknown_completer_foreign_key_is_rejected(string $class): void
    {
        $this->expectException(QueryException::class);
        $class::factory()->create(['completed_by' => 999999]);
    }

    public function test_completed_milestones_are_not_overdue(): void
    {
        $milestone = Milestone::factory()->completed()->create(['due_date' => '2000-01-01']);
        $this->assertFalse($milestone->isOverdue());
    }

    public static function models(): array
    {
        return [[Project::class], [Milestone::class]];
    }

    public static function immutableFields(): array
    {
        $cases = [];
        foreach ([Project::class, Milestone::class] as $class) {
            foreach (['completed_at', 'completed_by', 'completion_note'] as $field) {
                $cases[] = [$class, $field];
            }
        }

        return $cases;
    }
}
