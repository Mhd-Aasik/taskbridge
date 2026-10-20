<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_and_typed_relationships_preserve_offer_terms(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $proposal = $assignment->proposal;
        $this->assertTrue($assignment->project->assignment->is($assignment));
        $this->assertTrue($proposal->assignment->is($assignment));
        $this->assertTrue($assignment->freelancer->projectAssignments->sole()->is($assignment));
        $this->assertSame($proposal->project_id, $assignment->project_id);
        $this->assertSame($proposal->freelancer_id, $assignment->freelancer_id);
        $this->assertSame($proposal->proposed_budget, $assignment->agreed_budget);
        $this->assertSame($proposal->proposed_deadline->toDateString(), $assignment->agreed_deadline->toDateString());
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->status);
        $this->assertSame(ProposalStatus::Accepted, $proposal->status);
    }

    public function test_money_date_and_utc_timestamp_casts_are_exact(): void
    {
        $assignment = ProjectAssignment::factory()->create([
            'agreed_budget' => '9999999999.99', 'agreed_deadline' => '2027-01-12',
            'assigned_at' => CarbonImmutable::parse('2026-10-20 17:30:00', 'Asia/Colombo'),
        ])->fresh();
        $this->assertSame('9999999999.99', $assignment->agreed_budget);
        $this->assertSame('LKR 9,999,999,999.99', $assignment->formattedBudget());
        $this->assertInstanceOf(CarbonImmutable::class, $assignment->agreed_deadline);
        $this->assertSame('2027-01-12', $assignment->agreed_deadline->toDateString());
        $this->assertInstanceOf(CarbonImmutable::class, $assignment->assigned_at);
        $this->assertSame('2026-10-20 12:00:00', $assignment->getRawOriginal('assigned_at'));
        $this->assertSame('UTC', $assignment->assigned_at->timezoneName);
    }

    #[DataProvider('protectedFields')]
    public function test_system_fields_cannot_be_mass_assigned(string $field): void
    {
        $this->expectException(MassAssignmentException::class);
        (new ProjectAssignment)->fill([$field => '1']);
    }

    #[DataProvider('uniqueFields')]
    public function test_each_project_and_proposal_can_only_be_assigned_once(string $field): void
    {
        $first = ProjectAssignment::factory()->create();
        $other = Proposal::factory()->create();
        $values = [
            'project_id' => $other->project_id, 'proposal_id' => $other->id, 'freelancer_id' => $other->freelancer_id,
            'agreed_budget' => '100.00', 'agreed_deadline' => '2027-01-12', 'assigned_at' => now(),
        ];
        $values[$field] = $first->$field;
        $this->expectException(QueryException::class);
        DB::table('project_assignments')->insert($values);
    }

    #[DataProvider('references')]
    public function test_referenced_records_cannot_be_deleted(string $relation): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->expectException(QueryException::class);
        $assignment->$relation->delete();
    }

    #[DataProvider('foreignKeys')]
    public function test_unknown_foreign_keys_are_rejected(string $field): void
    {
        $proposal = Proposal::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('project_assignments')->insert(array_replace([
            'project_id' => $proposal->project_id, 'proposal_id' => $proposal->id, 'freelancer_id' => $proposal->freelancer_id,
            'agreed_budget' => '100.00', 'agreed_deadline' => '2027-01-12', 'assigned_at' => now(),
        ], [$field => 999999]));
    }

    public static function protectedFields(): array
    {
        return array_map(fn ($field) => [$field], ['project_id', 'proposal_id', 'freelancer_id', 'agreed_budget', 'agreed_deadline', 'assigned_at']);
    }

    public static function uniqueFields(): array
    {
        return [['project_id'], ['proposal_id']];
    }

    public static function references(): array
    {
        return [['project'], ['proposal'], ['freelancer']];
    }

    public static function foreignKeys(): array
    {
        return [['project_id'], ['proposal_id'], ['freelancer_id']];
    }
}
