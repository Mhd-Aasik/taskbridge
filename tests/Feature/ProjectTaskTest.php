<?php

namespace Tests\Feature;

use App\Enums\ProjectTaskStatus;
use App\Models\Milestone;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_relationships_defaults_and_calendar_date_casts(): void
    {
        $task = ProjectTask::factory()->create(['due_date' => '2028-02-29', 'description' => null])->fresh();
        $this->assertTrue($task->assignment->tasks->sole()->is($task));
        $this->assertTrue($task->creator->createdTasks->sole()->is($task));
        $this->assertSame($task->assignment->project->client_id, $task->created_by);
        $this->assertNull($task->milestone);
        $this->assertNull($task->description);
        $this->assertNull($task->completed_at);
        $this->assertSame(ProjectTaskStatus::Todo, $task->status);
        $this->assertSame(1, $task->details_version);
        $this->assertSame(1, $task->status_version);
        $this->assertInstanceOf(CarbonImmutable::class, $task->due_date);
        $this->assertSame('2028-02-29', $task->toArray()['due_date']);
        $this->assertNotNull($task->created_at);
        $this->assertNotNull($task->updated_at);
    }

    public function test_milestone_relationship_uses_the_matching_assignment(): void
    {
        $milestone = Milestone::factory()->create();
        $task = ProjectTask::factory()->create(['project_assignment_id' => $milestone->project_assignment_id, 'milestone_id' => $milestone->id]);
        $this->assertTrue($task->milestone->is($milestone));
        $this->assertTrue($milestone->tasks->sole()->is($task));
        $this->assertTrue($task->assignment->is($milestone->assignment));
    }

    public function test_database_defaults_apply_without_factory_status_values(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $id = DB::table('project_tasks')->insertGetId([
            'project_assignment_id' => $assignment->id, 'created_by' => $assignment->project->client_id,
            'title' => 'Database defaults', 'due_date' => '2027-01-12',
        ]);
        $task = ProjectTask::findOrFail($id);
        $this->assertSame(ProjectTaskStatus::Todo, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertSame(1, $task->details_version);
        $this->assertSame(1, $task->status_version);
    }

    public function test_completion_timestamp_is_immutable_and_normalized_to_utc(): void
    {
        $task = ProjectTask::factory()->create([
            'status' => ProjectTaskStatus::Done,
            'completed_at' => CarbonImmutable::parse('2026-10-23 17:30:00', 'Asia/Colombo'),
        ])->fresh();
        $this->assertSame(ProjectTaskStatus::Done, $task->status);
        $this->assertInstanceOf(CarbonImmutable::class, $task->completed_at);
        $this->assertSame('2026-10-23 12:00:00', $task->getRawOriginal('completed_at'));
        $this->assertSame('UTC', $task->completed_at->timezoneName);
        $task->forceFill(['completed_at' => null])->save();
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_mass_assignment_allows_only_ordinary_task_details(): void
    {
        $task = ProjectTask::factory()->create()->fresh();
        $before = $task->getAttributes();
        $protected = [
            'project_assignment_id' => 999999, 'milestone_id' => 999999, 'created_by' => 999999,
            'status' => ProjectTaskStatus::Done->value, 'completed_at' => '2000-01-01 00:00:00',
            'details_version' => 999, 'status_version' => 999,
        ];
        $new = new ProjectTask($protected + ['title' => 'Allowed title']);
        foreach (array_keys($protected) as $field) {
            $this->assertFalse($task->isFillable($field));
            $this->assertNotSame($protected[$field], $new->getRawOriginal($field));
        }
        $task->update($protected + ['title' => 'Updated title', 'description' => 'Plain text', 'due_date' => '2027-01-12']);
        $this->assertSame('Updated title', $task->fresh()->title);
        $this->assertSame('Plain text', $task->fresh()->description);
        $this->assertSame('2027-01-12', $task->fresh()->due_date->toDateString());
        foreach (array_keys($protected) as $field) {
            $this->assertSame($before[$field] ?? null, $task->fresh()->getRawOriginal($field));
        }
    }

    #[DataProvider('foreignKeys')]
    public function test_unknown_foreign_keys_are_rejected(string $field): void
    {
        $task = ProjectTask::factory()->make();
        $values = $task->getAttributes();
        $values[$field] = 999999;
        $this->expectException(QueryException::class);
        DB::table('project_tasks')->insert($values);
    }

    public function test_composite_foreign_key_rejects_a_milestone_from_another_assignment(): void
    {
        $milestone = Milestone::factory()->create();
        $assignment = ProjectAssignment::factory()->create();
        $this->expectException(QueryException::class);
        ProjectTask::factory()->create(['project_assignment_id' => $assignment->id, 'milestone_id' => $milestone->id]);
    }

    public function test_composite_foreign_key_also_rejects_direct_task_membership_updates(): void
    {
        $task = ProjectTask::factory()->create();
        $other = Milestone::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('project_tasks')->where('id', $task->id)->update(['milestone_id' => $other->id]);
    }

    public function test_referenced_milestone_cannot_be_moved_to_a_different_assignment(): void
    {
        $milestone = Milestone::factory()->create();
        ProjectTask::factory()->create(['project_assignment_id' => $milestone->project_assignment_id, 'milestone_id' => $milestone->id]);
        $other = ProjectAssignment::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('milestones')->where('id', $milestone->id)->update(['project_assignment_id' => $other->id]);
    }

    #[DataProvider('referencedParents')]
    public function test_task_parents_cannot_be_deleted(string $parent): void
    {
        $milestone = Milestone::factory()->create();
        $author = User::factory()->create();
        $task = ProjectTask::factory()->create([
            'project_assignment_id' => $milestone->project_assignment_id, 'milestone_id' => $milestone->id, 'created_by' => $author->id,
        ]);
        $this->expectException(QueryException::class);
        $task->$parent->delete();
    }

    public function test_ungrouped_task_still_prevents_assignment_deletion(): void
    {
        $task = ProjectTask::factory()->create(['milestone_id' => null]);
        $this->expectException(QueryException::class);
        $task->assignment->delete();
    }

    public function test_unfinished_tasks_become_overdue_at_sri_lankan_midnight_without_writes(): void
    {
        $task = ProjectTask::factory()->create(['due_date' => '2026-10-23'])->fresh();
        $before = $task->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 18:29:59', 'UTC'));
        $this->assertFalse($task->isOverdue());
        $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
        $this->assertTrue($task->isOverdue());
        $this->assertSame($before, $task->fresh()->getAttributes());
        $task->forceFill(['status' => ProjectTaskStatus::InProgress])->save();
        $this->assertTrue($task->isOverdue());
        $task->forceFill(['status' => ProjectTaskStatus::Done, 'completed_at' => now()])->save();
        $this->assertFalse($task->isOverdue());
    }

    public static function foreignKeys(): array
    {
        return [['project_assignment_id'], ['created_by'], ['milestone_id']];
    }

    public static function referencedParents(): array
    {
        return [['assignment'], ['milestone'], ['creator']];
    }
}
