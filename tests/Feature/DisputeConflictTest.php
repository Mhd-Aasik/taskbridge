<?php

namespace Tests\Feature;

use App\Actions\Disputes\OpenDispute;
use App\Actions\Disputes\ResolveDispute;
use App\Actions\Disputes\SubmitDisputeStatement;
use App\Models\ActiveDispute;
use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\DisputeStatement;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DisputeConflictTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('kinds')]
    public function test_injected_unique_conflicts_recover_exact_durable_winner(string $kind): void
    {
        // Winner insertion after rollback simulates a competing commit, not parallel MySQL execution.
        [$assignment, $case, $token, $actor, $class, $table, $column] = $this->context($kind);
        $attributes = $this->attributes($kind, $assignment, $case, $token, $actor);
        $class::creating(function () use ($table, $attributes, $column): never {
            DB::afterRollBack(function () use ($table, $attributes): void {
                $id = DB::table($table)->insertGetId($attributes);
                if ($table === 'dispute_cases') {
                    DB::table('active_disputes')->insert(['project_assignment_id' => $attributes['project_assignment_id'], 'dispute_case_id' => $id]);
                } elseif ($table === 'dispute_resolutions') {
                    DB::table('active_disputes')->where('dispute_case_id', $attributes['dispute_case_id'])->delete();
                }
            });
            throw $this->conflict($table.'.'.$column);
        });
        $result = $this->executeDispute($kind, $assignment, $case, $token);
        $this->assertSame($attributes[$column], $result->{$column});
        $time = match ($kind) {
            'opening' => 'opened_at', 'statement' => 'submitted_at', 'resolution' => 'decided_at'
        };
        $this->assertSame('2026-10-27 12:00:00', $result->{$time}->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount($table, 1);
    }

    public function test_injected_active_primary_collision_rolls_back_loser_case_and_returns_competing_case(): void
    {
        [$assignment, , $token, $actor] = $this->context('opening');
        $winner = $this->attributes('opening', $assignment, null, (string) Str::uuid(), $actor);
        $winner['title'] = 'Competing original title';
        ActiveDispute::creating(function () use ($winner): never {
            DB::afterRollBack(function () use ($winner): void {
                $id = DB::table('dispute_cases')->insertGetId($winner);
                DB::table('active_disputes')->insert(['project_assignment_id' => $winner['project_assignment_id'], 'dispute_case_id' => $id]);
            });
            throw $this->conflict('active_disputes.project_assignment_id', "Duplicate entry '1' for key 'PRIMARY'");
        });
        $result = $this->executeDispute('opening', $assignment, null, $token);
        $this->assertSame('Competing original title', $result->title);
        $this->assertDatabaseCount('dispute_cases', 1);
        $this->assertDatabaseCount('active_disputes', 1);
        $this->assertDatabaseMissing('dispute_cases', ['submission_id' => $token]);
    }

    #[DataProvider('kinds')]
    public function test_known_conflict_without_winner_is_not_swallowed(string $kind): void
    {
        [$assignment, $case, $token, , $class, $table, $column] = $this->context($kind);
        $failure = $this->conflict($table.'.'.$column);
        $class::creating(function () use ($failure): never {
            throw $failure;
        });
        $this->expectExceptionObject($failure);
        $this->executeDispute($kind, $assignment, $case, $token);
    }

    #[DataProvider('kinds')]
    public function test_unrelated_primary_conflicts_are_not_treated_as_retries(string $kind): void
    {
        [$assignment, $case, $token, , $class, $table] = $this->context($kind);
        $failure = $this->conflict($table.'.id', "Duplicate entry '1' for key 'PRIMARY'");
        $class::creating(function () use ($failure): never {
            throw $failure;
        });
        $this->expectExceptionObject($failure);
        $this->executeDispute($kind, $assignment, $case, $token);
    }

    #[DataProvider('kinds')]
    public function test_outer_transaction_failure_rolls_back_all_case_mutations(string $kind): void
    {
        [$assignment, $case, $token, , , $table] = $this->context($kind);
        $before = $assignment->fresh()->getAttributes();
        try {
            DB::transaction(function () use ($kind, $assignment, $case, $token): void {
                $this->executeDispute($kind, $assignment, $case, $token);
                throw new RuntimeException('Abort outer transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort outer transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount($table, 0);
        $this->assertDatabaseCount('active_disputes', $kind === 'opening' ? 0 : 1);
        $this->assertSame($before, $assignment->fresh()->getAttributes());
    }

    public function test_resolution_rolls_back_when_active_marker_removal_is_vetoed(): void
    {
        [$assignment, $case, $token] = $this->context('resolution');
        ActiveDispute::deleting(fn () => false);
        try {
            $this->executeDispute('resolution', $assignment, $case, $token);
            $this->fail('Closure cannot partially commit.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not be closed', $exception->getMessage());
        }
        $this->assertDatabaseCount('dispute_resolutions', 0);
        $this->assertDatabaseCount('active_disputes', 1);
    }

    #[DataProvider('kinds')]
    public function test_save_veto_never_reports_success(string $kind): void
    {
        [$assignment, $case, $token, , $class, $table] = $this->context($kind);
        $class::creating(fn () => false);
        try {
            $this->executeDispute($kind, $assignment, $case, $token);
            $this->fail('Save veto must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not', $exception->getMessage());
        }
        $this->assertDatabaseCount($table, 0);
        $this->assertDatabaseCount('active_disputes', $kind === 'opening' ? 0 : 1);
    }

    private function context(string $kind): array
    {
        $assignment = ProjectAssignment::factory()->create();
        $case = $kind === 'opening' ? null : DisputeCase::factory()->create(['project_assignment_id' => $assignment->id]);
        $actor = $kind === 'resolution' ? User::factory()->create(['role' => User::ROLE_ADMIN]) : $assignment->project->client;
        $this->actingAs($actor);
        [$class, $table, $column] = match ($kind) {
            'opening' => [DisputeCase::class, 'dispute_cases', 'submission_id'],
            'statement' => [DisputeStatement::class, 'dispute_statements', 'submission_id'],
            'resolution' => [DisputeResolution::class, 'dispute_resolutions', 'dispute_case_id'],
        };

        return [$assignment, $case, (string) Str::uuid(), $actor, $class, $table, $column];
    }

    private function attributes(string $kind, ProjectAssignment $assignment, ?DisputeCase $case, string $token, User $actor): array
    {
        $time = '2026-10-27 12:00:00';

        return (match ($kind) {
            'opening' => ['project_assignment_id' => $assignment->id, 'opened_by' => $actor->id, 'title' => 'Review outcome', 'reason' => 'Original reason', 'opened_at' => $time, 'submission_id' => $token],
            'statement' => ['dispute_case_id' => $case->id, 'submitted_by' => $actor->id, 'kind' => 'statement', 'body' => 'Original statement', 'submitted_at' => $time, 'submission_id' => $token],
            'resolution' => ['dispute_case_id' => $case->id, 'decided_by' => $actor->id, 'decision' => 'resolved', 'rationale' => 'Original rationale', 'decided_at' => $time],
        }) + ['created_at' => $time, 'updated_at' => $time];
    }

    private function executeDispute(string $kind, ProjectAssignment $assignment, ?DisputeCase $case, string $token): mixed
    {
        return match ($kind) {
            'opening' => app(OpenDispute::class)->open($assignment->project_id, ['title' => 'Review outcome', 'reason' => 'Original reason', 'confirmed' => true], $token),
            'statement' => app(SubmitDisputeStatement::class)->submit($case->id, ['body' => 'Original statement'], $token),
            'resolution' => app(ResolveDispute::class)->resolve($case->id, ['decision' => 'resolved', 'rationale' => 'Original rationale', 'confirmed' => true]),
        };
    }

    private function conflict(string $column, ?string $message = null): UniqueConstraintViolationException
    {
        $pdo = new PDOException($message ?? 'UNIQUE constraint failed: '.$column);
        $pdo->errorInfo = ['23000', 19, $pdo->getMessage()];

        return new UniqueConstraintViolationException('sqlite', 'insert simulated conflict', [], $pdo);
    }

    public static function kinds(): array
    {
        return [['opening'], ['statement'], ['resolution']];
    }
}
