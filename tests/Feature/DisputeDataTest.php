<?php

namespace Tests\Feature;

use App\Enums\DisputeDecision;
use App\Enums\DisputeStatementKind;
use App\Models\ActiveDispute;
use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\DisputeStatement;
use App\Models\ProjectAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DisputeDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_relationships_states_and_utc_casts(): void
    {
        $case = DisputeCase::factory()->create(['opened_at' => CarbonImmutable::parse('2026-10-27 00:15:00', 'Asia/Colombo')]);
        $statement = DisputeStatement::factory()->create(['dispute_case_id' => $case->id, 'submitted_at' => '2026-10-27T00:15:00+05:30']);
        $this->assertTrue($case->isOpen());
        $this->assertTrue($case->assignment->disputes->sole()->is($case));
        $this->assertTrue($case->opener->openedDisputes->sole()->is($case));
        $this->assertTrue($case->activeRecord->assignment->is($case->assignment));
        $this->assertTrue($case->activeRecord->dispute->is($case));
        $this->assertTrue($statement->dispute->is($case));
        $this->assertTrue($statement->author->disputeStatements->sole()->is($statement));
        $this->assertSame(DisputeStatementKind::Statement, $statement->fresh()->kind);
        $this->assertSame('2026-10-26 18:45:00', $case->fresh()->opened_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-26 18:45:00', $statement->fresh()->submitted_at->format('Y-m-d H:i:s'));
        $resolution = DisputeResolution::factory()->create(['dispute_case_id' => $case->id, 'decided_at' => '2026-10-28T00:15:00+05:30']);
        $this->assertSame(DisputeDecision::Resolved, $resolution->fresh()->decision);
        $this->assertTrue($resolution->dispute->is($case));
        $this->assertTrue($resolution->decider->disputeResolutions->sole()->is($resolution));
        $this->assertSame('2026-10-27 18:45:00', $resolution->fresh()->decided_at->format('Y-m-d H:i:s'));
        $this->assertSame('resolved', $case->fresh()->state());
        $this->assertNull($case->fresh()->activeRecord);
        $later = DisputeCase::factory()->create(['project_assignment_id' => $case->project_assignment_id]);
        $this->assertTrue($later->isOpen());
        $this->assertSame(2, $case->assignment->disputes()->count());
        $this->assertArrayNotHasKey('submission_id', $case->toArray());
        $this->assertArrayNotHasKey('submission_id', $statement->toArray());
    }

    #[DataProvider('recordClasses')]
    public function test_all_record_fields_are_guarded(string $class): void
    {
        $this->expectException(MassAssignmentException::class);
        (new $class)->fill(['id' => 4, 'project_assignment_id' => 4, 'dispute_case_id' => 4, 'title' => 'Forged', 'body' => 'Forged', 'decision' => 'resolved', 'created_at' => now()]);
    }

    #[DataProvider('immutableRecords')]
    public function test_original_records_cannot_be_updated_or_deleted(string $class, string $operation): void
    {
        $record = $class::factory()->create();
        $this->expectException(LogicException::class);
        if ($operation === 'delete') {
            $record->delete();
        } else {
            $record->updated_at = now()->addDay();
            $record->save();
        }
    }

    public function test_active_marker_cannot_be_closed_without_resolution(): void
    {
        $case = DisputeCase::factory()->create();
        $this->expectException(LogicException::class);
        $case->activeRecord->delete();
    }

    public function test_composite_foreign_key_rejects_an_active_marker_for_another_assignment(): void
    {
        $case = DisputeCase::factory()->create();
        DB::table('active_disputes')->where('dispute_case_id', $case->id)->delete();
        $other = ProjectAssignment::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('active_disputes')->insert(['project_assignment_id' => $other->id, 'dispute_case_id' => $case->id]);
    }

    #[DataProvider('integrityViolations')]
    public function test_database_enforces_identity_uniqueness_and_restrictive_foreign_keys(string $violation): void
    {
        $case = DisputeCase::factory()->create();
        $statement = DisputeStatement::factory()->create(['dispute_case_id' => $case->id]);
        $resolution = null;
        if (in_array($violation, ['resolution_unique', 'decider_delete', 'case_delete'], true)) {
            $resolution = DisputeResolution::factory()->create(['dispute_case_id' => $case->id]);
        }
        $this->expectException(QueryException::class);
        match ($violation) {
            'active_assignment_unique' => DB::table('active_disputes')->insert(['project_assignment_id' => $case->project_assignment_id, 'dispute_case_id' => $case->id]),
            'active_mismatched_assignment' => DB::table('active_disputes')->insert(['project_assignment_id' => ProjectAssignment::factory()->create()->id, 'dispute_case_id' => $case->id]),
            'case_uuid_unique' => DB::table('dispute_cases')->insert(array_merge($case->getAttributes(), ['id' => 9999])),
            'statement_uuid_unique' => DB::table('dispute_statements')->insert(array_merge($statement->getAttributes(), ['id' => 9999])),
            'resolution_unique' => DB::table('dispute_resolutions')->insert(array_merge($resolution->getAttributes(), ['id' => 9999])),
            'assignment_delete' => DB::table('project_assignments')->where('id', $case->project_assignment_id)->delete(),
            'opener_delete' => DB::table('users')->where('id', $case->opened_by)->delete(),
            'author_delete' => DB::table('users')->where('id', $statement->submitted_by)->delete(),
            'decider_delete' => DB::table('users')->where('id', $resolution->decided_by)->delete(),
            'case_delete' => DB::table('dispute_cases')->where('id', $case->id)->delete(),
            'statement_missing_case' => DB::table('dispute_statements')->insert(array_merge($statement->getAttributes(), ['id' => 9999, 'dispute_case_id' => 9999, 'submission_id' => (string) Str::uuid()])),
        };
    }

    public static function recordClasses(): array
    {
        return array_map(fn ($class) => [$class], [DisputeCase::class, ActiveDispute::class, DisputeStatement::class, DisputeResolution::class]);
    }

    public static function immutableRecords(): array
    {
        $cases = [];
        foreach ([DisputeCase::class, DisputeStatement::class, DisputeResolution::class] as $class) {
            foreach (['update', 'delete'] as $operation) {
                $cases[] = [$class, $operation];
            }
        }

        return $cases;
    }

    public static function integrityViolations(): array
    {
        return array_map(fn ($case) => [$case], ['active_assignment_unique', 'active_mismatched_assignment', 'case_uuid_unique', 'statement_uuid_unique', 'resolution_unique', 'assignment_delete', 'opener_delete', 'author_delete', 'decider_delete', 'case_delete', 'statement_missing_case']);
    }
}
