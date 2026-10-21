<?php

namespace Tests\Feature;

use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\Milestone;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 18:45:00', 'UTC'));
        Storage::fake('deliverables');
    }

    #[DataProvider('allowedStates')]
    public function test_client_reports_exact_agreed_amount_and_server_metadata_without_changing_work(ProjectStatus $status): void
    {
        $assignment = $this->assignment(['agreed_budget' => '9999999999.99']);
        $assignment->project->forceFill(['status' => $status])->save();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $task = ProjectTask::factory()->create(['milestone_id' => $milestone->id]);
        $deliverable = Deliverable::factory()->create(['project_assignment_id' => $assignment->id, 'milestone_id' => $milestone->id]);
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $review = DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $version->id]);
        Storage::disk('deliverables')->put($version->storage_path, 'Keep original files.');
        $records = [$assignment->project, $assignment, $assignment->proposal, $milestone, $task, $version, $review];
        $before = array_map(fn ($record) => $record->fresh()->getAttributes(), $records);
        $this->actingAs($assignment->project->client);
        $token = (string) Str::uuid();
        $report = $this->report($assignment, [
            'amount' => '1.00', 'currency' => 'USD', 'reported_by' => 999999,
            'project_assignment_id' => 999999, 'reported_at' => '2000-01-01', 'created_at' => '2000-01-01',
            'submission_id' => (string) Str::uuid(), 'id' => 999999, 'status' => 'cancelled', 'role' => 'admin',
            'reference' => '  Bank reference  ', 'note' => "  Client note\n<script>unsafe</script>  ",
        ], $token)->fresh();
        $this->assertSame('9999999999.99', $report->amount);
        $this->assertSame('LKR', $report->currency);
        $this->assertSame($assignment->id, $report->project_assignment_id);
        $this->assertSame($assignment->project->client_id, $report->reported_by);
        $this->assertSame('2026-10-26 18:45:00', $report->reported_at->format('Y-m-d H:i:s'));
        $this->assertSame($token, $report->submission_id);
        $this->assertSame('Bank reference', $report->reference);
        $this->assertSame("Client note\n<script>unsafe</script>", $report->note);
        $this->assertNull($report->acknowledgment);

        $this->actingAs($assignment->freelancer);
        $receipt = $this->acknowledge($assignment, $report, [
            'note' => '  Received outside TaskBridge.  ', 'payment_report_id' => 999999,
            'acknowledged_by' => 999999, 'acknowledged_at' => '2000-01-01', 'created_at' => '2000-01-01', 'role' => 'admin',
        ])->fresh();
        $this->assertSame($report->id, $receipt->payment_report_id);
        $this->assertSame($assignment->freelancer_id, $receipt->acknowledged_by);
        $this->assertSame('2026-10-26 18:45:00', $receipt->acknowledged_at->format('Y-m-d H:i:s'));
        $this->assertSame('Received outside TaskBridge.', $receipt->note);
        foreach ($records as $index => $record) {
            $this->assertSame($before[$index], $record->fresh()->getAttributes());
        }
        $this->assertSame('Keep original files.', Storage::disk('deliverables')->get($version->storage_path));
    }

    #[DataProvider('validMethodsAndDates')]
    public function test_supported_methods_date_bounds_and_late_payment_after_work_deadline(string $method, string $date): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $report = $this->report($assignment, ['method' => $method, 'paid_on' => $date]);
        $this->assertSame(PaymentMethod::from($method), $report->method);
        $this->assertSame($date, $report->paid_on->toDateString());
    }

    #[DataProvider('invalidReports')]
    public function test_report_validation_rejects_bad_input_without_writes(array $input, string $field): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->invalid(fn () => $this->report($assignment, $input), $field);
        $this->assertDatabaseCount('payment_reports', 0);
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }

    #[DataProvider('badTerms')]
    public function test_saved_agreed_budget_is_revalidated_before_decimal_cast_rounding(string $amount): void
    {
        $assignment = $this->assignment();
        DB::table('project_assignments')->where('id', $assignment->id)->update(['agreed_budget' => $amount]);
        $this->actingAs($assignment->project->client);
        $this->invalid(fn () => $this->report($assignment), 'agreed_amount');
        $this->assertDatabaseCount('payment_reports', 0);
    }

    public function test_reference_and_note_boundaries_and_nullable_normalization(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $report = $this->report($assignment, ['reference' => str_repeat('r', 150), 'note' => str_repeat('n', 2000)]);
        $this->assertSame(150, strlen($report->reference));
        $this->assertSame(2000, strlen($report->note));
        $this->actingAs($assignment->freelancer);
        $this->assertSame(str_repeat('n', 2000), $this->acknowledge($assignment, $report, ['note' => str_repeat('n', 2000)])->note);
        $other = $this->assignment();
        $this->actingAs($other->project->client);
        $empty = $this->report($other, ['reference' => " \n\t ", 'note' => " \n\t "]);
        $this->assertNull($empty->reference);
        $this->assertNull($empty->note);
        $this->actingAs($other->freelancer);
        $this->assertNull($this->acknowledge($other, $empty, ['note' => '   '])->note);
    }

    public function test_sri_lankan_midnight_changes_the_last_valid_paid_on_date(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $token = (string) Str::uuid();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 18:29:59', 'UTC'));
        $this->invalid(fn () => $this->report($assignment, ['paid_on' => '2026-10-27'], $token), 'paid_on');
        $this->travel(1)->seconds();
        $report = $this->report($assignment, ['paid_on' => '2026-10-27'], $token);
        $this->assertSame('2026-10-27', $report->paid_on->toDateString());
        $this->assertSame('2026-10-26 18:30:00', $report->reported_at->format('Y-m-d H:i:s'));
    }

    public function test_invalid_retry_identifier_is_rejected_without_creating_a_report(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->invalid(fn () => $this->report($assignment, [], 'not-a-uuid'), 'submission_id');
        $this->assertDatabaseCount('payment_reports', 0);
    }

    #[DataProvider('forbiddenReporters')]
    public function test_guests_wrong_roles_and_unrelated_accounts_cannot_report(string $role): void
    {
        $assignment = $this->assignment();
        $actor = match ($role) {
            'guest' => null,
            'assigned_freelancer' => $assignment->freelancer,
            default => User::factory()->create(['role' => $role]),
        };
        $actor ? $this->actingAs($actor) : Auth::logout();
        $this->forbidden(fn () => $this->report($assignment));
        $this->assertDatabaseCount('payment_reports', 0);
    }

    #[DataProvider('forbiddenAcknowledgers')]
    public function test_only_assigned_freelancer_can_acknowledge(string $role): void
    {
        $assignment = $this->assignment();
        $report = PaymentReport::factory()->create(['project_assignment_id' => $assignment->id]);
        $actor = match ($role) {
            'guest' => null,
            'owning_client' => $assignment->project->client,
            default => User::factory()->create(['role' => $role]),
        };
        $actor ? $this->actingAs($actor) : Auth::logout();
        $this->forbidden(fn () => $this->acknowledge($assignment, $report));
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }

    public function test_acknowledgment_requires_an_existing_correctly_scoped_report(): void
    {
        $assignment = $this->assignment();
        $foreign = PaymentReport::factory()->create();
        $this->actingAs($assignment->freelancer);
        foreach ([$foreign->id, 999999] as $id) {
            try {
                app(AcknowledgePayment::class)->acknowledge($assignment->project_id, $id, ['confirmed' => true]);
                $this->fail('A missing or mismatched report must be refused.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('payment_acknowledgments', 0);
            }
        }
    }

    #[DataProvider('invalidReceipts')]
    public function test_acknowledgment_validation_preserves_the_report(array $input, string $field): void
    {
        $report = PaymentReport::factory()->create()->fresh();
        $before = $report->getAttributes();
        $this->actingAs($report->assignment->freelancer);
        $this->invalid(fn () => $this->acknowledge($report->assignment, $report, $input), $field);
        $this->assertSame($before, $report->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }

    public function test_exact_report_and_acknowledgment_retries_preserve_original_values_and_timestamps(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $token = (string) Str::uuid();
        $report = $this->report($assignment, ['note' => '  Original note  '], $token)->fresh();
        $before = $report->getAttributes();
        $this->travel(1)->days();
        $this->assertSame($before, $this->report($assignment, ['note' => "\nOriginal note\t"], strtoupper($token))->fresh()->getAttributes());
        $this->invalid(fn () => $this->report($assignment, ['note' => 'Changed'], $token), 'payment');
        $this->invalid(fn () => $this->report($assignment, ['note' => 'Original note']), 'payment');
        $this->actingAs($assignment->freelancer);
        $receipt = $this->acknowledge($assignment, $report, ['note' => '  Received  '])->fresh();
        $receiptBefore = $receipt->getAttributes();
        $this->travel(1)->days();
        $this->assertSame($receiptBefore, $this->acknowledge($assignment, $report, ['note' => "\nReceived\t"])->fresh()->getAttributes());
        $this->invalid(fn () => $this->acknowledge($assignment, $report, ['note' => 'Changed']), 'receipt');
        $this->assertSame($before, $report->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_reports', 1);
        $this->assertDatabaseCount('payment_acknowledgments', 1);
    }

    #[DataProvider('closedStates')]
    public function test_new_report_refuses_other_states_but_receipt_and_exact_retry_keep_historical_access(ProjectStatus $status): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $token = (string) Str::uuid();
        $report = $this->report($assignment, [], $token)->fresh();
        $before = $report->getAttributes();
        $assignment->project->forceFill(['status' => $status])->save();
        $this->assertSame($before, $this->report($assignment, [], $token)->fresh()->getAttributes());
        $this->actingAs($assignment->freelancer);
        $this->acknowledge($assignment, $report);
        $other = $this->assignment();
        $other->project->forceFill(['status' => $status])->save();
        $this->actingAs($other->project->client);
        $this->invalid(fn () => $this->report($other), 'payment');
    }

    public function test_persisted_roles_ownership_and_assignment_are_rechecked(): void
    {
        $assignment = $this->assignment();
        $client = $assignment->project->client;
        $this->actingAs($client);
        DB::table('users')->where('id', $client->id)->update(['role' => User::ROLE_ADMIN]);
        $this->forbidden(fn () => $this->report($assignment));
        DB::table('users')->where('id', $client->id)->update(['role' => User::ROLE_CLIENT]);
        $newClient = User::factory()->create(['role' => User::ROLE_CLIENT]);
        DB::table('projects')->where('id', $assignment->project_id)->update(['client_id' => $newClient->id]);
        $this->forbidden(fn () => $this->report($assignment));
        $report = PaymentReport::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->freelancer);
        DB::table('project_assignments')->where('id', $assignment->id)->update(['freelancer_id' => User::factory()->create(['role' => User::ROLE_FREELANCER])->id]);
        $this->forbidden(fn () => $this->acknowledge($assignment, $report));
    }

    public function test_submission_uuid_cannot_resolve_another_projects_report(): void
    {
        $foreign = PaymentReport::factory()->create();
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->invalid(fn () => $this->report($assignment, [], $foreign->submission_id), 'payment');
        $this->assertDatabaseCount('payment_reports', 1);
        $this->assertNull($assignment->fresh()->paymentReport);
    }

    #[DataProvider('saveFailures')]
    public function test_atomic_rollback_on_save_veto_or_failure_after_insert(string $kind, bool $afterInsert): void
    {
        $assignment = $this->assignment();
        $report = $kind === 'receipt' ? PaymentReport::factory()->create(['project_assignment_id' => $assignment->id]) : null;
        $class = $kind === 'report' ? PaymentReport::class : PaymentAcknowledgment::class;
        if ($afterInsert) {
            $class::created(function (): never {
                throw new RuntimeException('Injected after-insert failure');
            });
        } else {
            $class::creating(fn () => false);
        }
        $this->actingAs($kind === 'report' ? $assignment->project->client : $assignment->freelancer);
        try {
            $kind === 'report' ? $this->report($assignment) : $this->acknowledge($assignment, $report);
            $this->fail('A failed history insert must roll back.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('payment_reports', $kind === 'report' ? 0 : 1);
            $this->assertDatabaseCount('payment_acknowledgments', 0);
        }
    }

    public function test_simulated_two_report_forms_and_two_receipt_forms_keep_the_first_committed_result(): void
    {
        // Sequential competing confirmations, not parallel MySQL execution.
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $firstToken = (string) Str::uuid();
        $secondToken = (string) Str::uuid();
        $first = $this->report($assignment, [], $firstToken)->fresh();
        $before = $first->getAttributes();
        $this->invalid(fn () => $this->report($assignment, [], $secondToken), 'payment');
        $this->actingAs($assignment->freelancer);
        $receipt = $this->acknowledge($assignment, $first, ['note' => 'First receipt'])->fresh();
        $receiptBefore = $receipt->getAttributes();
        $this->invalid(fn () => $this->acknowledge($assignment, $first, ['note' => 'Competing receipt']), 'receipt');
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertSame($receiptBefore, $receipt->fresh()->getAttributes());
    }

    private function assignment(array $attributes = []): ProjectAssignment
    {
        return ProjectAssignment::factory()->create(array_replace(['assigned_at' => '2026-10-25 20:00:00', 'agreed_deadline' => '2026-10-26', 'agreed_budget' => '12345.40'], $attributes));
    }

    private function report(ProjectAssignment $assignment, array $input = [], ?string $token = null): PaymentReport
    {
        return app(ReportPayment::class)->report($assignment->project_id, array_replace(['confirmed' => true, 'method' => 'bank_transfer', 'paid_on' => '2026-10-27'], $input), $token ?? (string) Str::uuid());
    }

    private function acknowledge(ProjectAssignment $assignment, PaymentReport $report, array $input = []): PaymentAcknowledgment
    {
        return app(AcknowledgePayment::class)->acknowledge($assignment->project_id, $report->id, array_replace(['confirmed' => true], $input));
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Invalid payment input must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function forbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('An unauthorized actor must be refused.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $exception->status() ?? 403);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public static function allowedStates(): array
    {
        return [[ProjectStatus::InProgress], [ProjectStatus::Completed]];
    }

    public static function validMethodsAndDates(): array
    {
        return [['bank_transfer', '2026-10-26'], ['cash', '2026-10-27'], ['other', '2026-10-27']];
    }

    public static function invalidReports(): array
    {
        return [
            [['confirmed' => false], 'confirmed'], [['confirmed' => []], 'confirmed'],
            [['method' => 'cheque'], 'method'], [['method' => []], 'method'], [['method' => ''], 'method'],
            [['paid_on' => '2026-10-25'], 'paid_on'], [['paid_on' => '2026-10-28'], 'paid_on'],
            [['paid_on' => '2026-02-30'], 'paid_on'], [['paid_on' => '27/10/2026'], 'paid_on'],
            [['paid_on' => '2026-10-27T00:00:00Z'], 'paid_on'], [['paid_on' => []], 'paid_on'],
            [['reference' => str_repeat('r', 151)], 'reference'], [['reference' => []], 'reference'],
            [['note' => str_repeat('n', 2001)], 'note'], [['note' => []], 'note'],
        ];
    }

    public static function badTerms(): array
    {
        return [['0.50'], ['-1.00'], ['1000.001'], ['10000000000.00'], ['not-money']];
    }

    public static function forbiddenReporters(): array
    {
        return [['guest'], ['assigned_freelancer'], [User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function forbiddenAcknowledgers(): array
    {
        return [['guest'], ['owning_client'], [User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function invalidReceipts(): array
    {
        return [[['confirmed' => false], 'confirmed'], [['confirmed' => []], 'confirmed'], [['note' => []], 'note'], [['note' => str_repeat('n', 2001)], 'note']];
    }

    public static function closedStates(): array
    {
        return [[ProjectStatus::Draft], [ProjectStatus::Open], [ProjectStatus::Cancelled]];
    }

    public static function saveFailures(): array
    {
        return [['report', false], ['report', true], ['receipt', false], ['receipt', true]];
    }
}
