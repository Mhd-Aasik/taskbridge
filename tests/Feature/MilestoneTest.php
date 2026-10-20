<?php

namespace Tests\Feature;

use App\Models\Milestone;
use App\Models\ProjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MilestoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_relationships_and_calendar_date_casts(): void
    {
        $milestone = Milestone::factory()->create(['due_date' => '2028-02-29', 'description' => null])->fresh();
        $this->assertTrue($milestone->assignment->milestones->sole()->is($milestone));
        $this->assertTrue($milestone->creator->createdMilestones->sole()->is($milestone));
        $this->assertSame($milestone->assignment->project->client_id, $milestone->created_by);
        $this->assertInstanceOf(CarbonImmutable::class, $milestone->due_date);
        $this->assertSame('2028-02-29', $milestone->toArray()['due_date']);
        $this->assertIsInt($milestone->position);
        $this->assertNull($milestone->description);
        $this->assertNotNull($milestone->created_at);
        $this->assertNotNull($milestone->updated_at);
    }

    public function test_factories_can_create_multiple_milestones_for_one_assignment(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestones = Milestone::factory()->count(3)->create(['project_assignment_id' => $assignment->id]);
        $this->assertCount(3, $assignment->milestones);
        $this->assertSame(3, $milestones->pluck('position')->unique()->count());
        $this->assertSame($milestones->sortBy('position')->values()->modelKeys(), $assignment->milestones->modelKeys());
    }

    public function test_mass_assignment_cannot_change_assignment_creator_or_position(): void
    {
        $milestone = Milestone::factory()->create()->fresh();
        $other = Milestone::factory()->create();
        $protected = ['project_assignment_id' => $other->project_assignment_id, 'created_by' => $other->created_by, 'position' => $other->position];
        $new = new Milestone($protected + ['title' => 'Allowed title']);
        foreach ($protected as $field => $value) {
            $this->assertFalse($milestone->isFillable($field));
            $this->assertNull($new->$field);
        }
        $before = $milestone->getAttributes();
        $milestone->update($protected + ['title' => 'Updated title']);
        $this->assertSame('Updated title', $milestone->fresh()->title);
        foreach (array_keys($protected) as $field) {
            $this->assertSame($before[$field], $milestone->fresh()->getRawOriginal($field));
        }
    }

    #[DataProvider('foreignKeys')]
    public function test_unknown_foreign_keys_are_rejected(string $field): void
    {
        $this->expectException(QueryException::class);
        Milestone::factory()->create(array_replace([
            'created_by' => User::factory()->create()->id, 'due_date' => '2027-01-12', 'position' => 1,
        ], [$field => 999999]));
    }

    #[DataProvider('parents')]
    public function test_assignment_and_author_deletion_is_restricted(string $parent): void
    {
        $milestone = Milestone::factory()->create();
        // Use a standalone author to ensure it is the new FK, not project history, that restricts deletion.
        $author = User::factory()->create();
        $milestone->creator()->associate($author);
        $milestone->save();
        $this->expectException(QueryException::class);
        $milestone->$parent->delete();
    }

    public function test_duplicate_positions_are_rejected_within_an_assignment(): void
    {
        $first = Milestone::factory()->create(['position' => 1]);
        $this->expectException(QueryException::class);
        Milestone::factory()->create(['project_assignment_id' => $first->project_assignment_id, 'position' => 1]);
    }

    public function test_positions_can_repeat_between_assignments(): void
    {
        Milestone::factory()->count(2)->create(['position' => 1]);
        $this->assertDatabaseCount('milestones', 2);
    }

    public function test_overdue_changes_at_sri_lankan_midnight_without_writing_records(): void
    {
        $milestone = Milestone::factory()->create(['due_date' => '2026-10-23'])->fresh();
        $before = $milestone->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 18:29:59', 'UTC'));
        $this->assertFalse($milestone->isOverdue());
        $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
        $this->assertTrue($milestone->isOverdue());
        $this->assertSame($before, $milestone->fresh()->getAttributes());
    }

    public static function foreignKeys(): array
    {
        return [['project_assignment_id'], ['created_by']];
    }

    public static function parents(): array
    {
        return [['assignment'], ['creator']];
    }
}
