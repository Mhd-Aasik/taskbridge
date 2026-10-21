<?php

namespace Tests\Feature;

use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\ProjectAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaymentConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 18:45:00', 'UTC'));
    }

    #[DataProvider('knownConflicts')]
    public function test_injected_unique_conflict_recovers_only_the_exact_original_history(string $kind, bool $matching): void
    {
        // A winner is injected after rollback. This is not real parallel MySQL testing.
        [$assignment, $report, $token] = $this->context($kind);
        $class = $kind === 'report' ? PaymentReport::class : PaymentAcknowledgment::class;
        $time = '2026-10-26 18:44:00';
        $class::creating(function () use ($kind, $assignment, $report, $token, $time): never {
            DB::afterRollBack(function () use ($kind, $assignment, $report, $token, $time): void {
                $data = $kind === 'report' ? [
                    'project_assignment_id' => $assignment->id, 'amount' => $assignment->agreed_budget, 'currency' => 'LKR',
                    'method' => 'cash', 'paid_on' => '2026-10-27', 'reference' => null, 'note' => 'Original note',
                    'reported_by' => $assignment->project->client_id, 'reported_at' => $time, 'submission_id' => $token,
                ] : ['payment_report_id' => $report->id, 'acknowledged_by' => $assignment->freelancer_id, 'acknowledged_at' => $time, 'note' => 'Original note'];
                DB::table($kind === 'report' ? 'payment_reports' : 'payment_acknowledgments')->insert($data + ['created_at' => $time, 'updated_at' => $time]);
            });
            throw $this->conflict($kind === 'report' ? 'payment_reports.project_assignment_id' : 'payment_acknowledgments.payment_report_id');
        });
        try {
            $result = $this->executePayment($kind, $assignment, $report, $token, $matching ? 'Original note' : 'Changed note');
            $this->assertTrue($matching);
            $field = $kind === 'report' ? 'reported_at' : 'acknowledged_at';
            $this->assertSame($time, $result->{$field}->format('Y-m-d H:i:s'));
        } catch (ValidationException $exception) {
            $this->assertFalse($matching);
            $this->assertArrayHasKey($kind === 'report' ? 'payment' : 'receipt', $exception->errors());
        }
        $this->assertDatabaseCount($kind === 'report' ? 'payment_reports' : 'payment_acknowledgments', 1);
        $this->assertSame('Original note', $class::sole()->note);
    }

    #[DataProvider('kinds')]
    public function test_known_conflict_without_a_durable_winner_is_not_swallowed(string $kind): void
    {
        [$assignment, $report, $token] = $this->context($kind);
        $class = $kind === 'report' ? PaymentReport::class : PaymentAcknowledgment::class;
        $failure = $this->conflict($kind === 'report' ? 'payment_reports.project_assignment_id' : 'payment_acknowledgments.payment_report_id');
        $class::creating(function () use ($failure): never {
            throw $failure;
        });
        try {
            $this->executePayment($kind, $assignment, $report, $token);
            $this->fail('A missing winner must not be treated as a successful retry.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    #[DataProvider('kinds')]
    public function test_unrelated_unique_constraint_failures_propagate(string $kind): void
    {
        [$assignment, $report, $token] = $this->context($kind);
        $class = $kind === 'report' ? PaymentReport::class : PaymentAcknowledgment::class;
        $existing = $class::factory()->create();
        $class::creating(function ($record) use ($existing): void {
            $record->id = $existing->id;
        });
        try {
            $this->executePayment($kind, $assignment, $report, $token);
            $this->fail('An unrelated primary-key failure must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString($existing->getTable().'.id', (string) ($exception->errorInfo[2] ?? ''));
        }
    }

    #[DataProvider('kinds')]
    public function test_outer_transaction_rollback_preserves_original_records(string $kind): void
    {
        [$assignment, $report, $token] = $this->context($kind);
        $before = $assignment->fresh()->getAttributes();
        try {
            DB::transaction(function () use ($kind, $assignment, $report, $token): void {
                $this->executePayment($kind, $assignment, $report, $token);
                throw new RuntimeException('Abort outer transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort outer transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount('payment_reports', $kind === 'report' ? 0 : 1);
        $this->assertDatabaseCount('payment_acknowledgments', 0);
        $this->assertSame($before, $assignment->fresh()->getAttributes());
    }

    public function test_injected_submission_uuid_collision_never_returns_another_assignments_report(): void
    {
        [$assignment, , $token] = $this->context('report');
        $foreign = ProjectAssignment::factory()->create();
        PaymentReport::creating(function () use ($foreign, $token): never {
            DB::afterRollBack(function () use ($foreign, $token): void {
                DB::table('payment_reports')->insert([
                    'project_assignment_id' => $foreign->id, 'amount' => $foreign->agreed_budget, 'currency' => 'LKR',
                    'method' => 'cash', 'paid_on' => '2026-10-27', 'reported_by' => $foreign->project->client_id,
                    'reported_at' => now(), 'submission_id' => $token, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
            throw $this->conflict('payment_reports.submission_id');
        });
        try {
            $this->executePayment('report', $assignment, null, $token);
            $this->fail('A UUID collision cannot cross assignment scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }
        $this->assertNull($assignment->fresh()->paymentReport);
        $this->assertSame($foreign->id, PaymentReport::sole()->project_assignment_id);
    }

    private function context(string $kind): array
    {
        $assignment = ProjectAssignment::factory()->create(['assigned_at' => '2026-10-25 20:00:00']);
        $report = $kind === 'receipt' ? PaymentReport::factory()->create(['project_assignment_id' => $assignment->id]) : null;
        $this->actingAs($kind === 'report' ? $assignment->project->client : $assignment->freelancer);

        return [$assignment, $report, (string) Str::uuid()];
    }

    private function executePayment(string $kind, ProjectAssignment $assignment, ?PaymentReport $report, string $token, string $note = 'Original note'): PaymentReport|PaymentAcknowledgment
    {
        return $kind === 'report'
            ? app(ReportPayment::class)->report($assignment->project_id, ['confirmed' => true, 'method' => 'cash', 'paid_on' => '2026-10-27', 'note' => $note], $token)
            : app(AcknowledgePayment::class)->acknowledge($assignment->project_id, $report->id, ['confirmed' => true, 'note' => $note]);
    }

    private function conflict(string $column): UniqueConstraintViolationException
    {
        $previous = new PDOException('Injected payment uniqueness conflict');
        $previous->errorInfo = ['23000', 19, 'UNIQUE constraint failed: '.$column];

        return new UniqueConstraintViolationException('sqlite', 'insert', [], $previous);
    }

    public static function kinds(): array
    {
        return [['report'], ['receipt']];
    }

    public static function knownConflicts(): array
    {
        return [['report', true], ['report', false], ['receipt', true], ['receipt', false]];
    }
}
