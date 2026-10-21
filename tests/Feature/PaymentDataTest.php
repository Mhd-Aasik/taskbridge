<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_create_typed_relationships_and_trusted_reference_data(): void
    {
        $receipt = PaymentAcknowledgment::factory()->create()->fresh();
        $report = $receipt->report;
        $assignment = $report->assignment;
        $this->assertTrue($assignment->paymentReport->is($report));
        $this->assertTrue($report->acknowledgment->is($receipt));
        $this->assertTrue($report->reporter->reportedPayments->sole()->is($report));
        $this->assertTrue($receipt->acknowledger->paymentAcknowledgments->sole()->is($receipt));
        $this->assertSame($assignment->project->client_id, $report->reported_by);
        $this->assertSame($assignment->freelancer_id, $receipt->acknowledged_by);
        $this->assertSame($assignment->agreed_budget, $report->amount);
        $this->assertSame('LKR', $report->currency);
        $this->assertSame(PaymentMethod::BankTransfer, $report->method);
        $this->assertSame('Bank transfer', $report->method->label());
        $this->assertNull($report->reference);
        $this->assertNull($report->note);
        $this->assertNull($receipt->note);
        $this->assertInstanceOf(CarbonImmutable::class, $report->paid_on);
        $this->assertArrayNotHasKey('submission_id', $report->toArray());
    }

    #[DataProvider('amounts')]
    public function test_decimal_amounts_remain_precise_strings_and_calendar_dates_are_not_shifted(string $amount, string $formatted): void
    {
        $report = PaymentReport::factory()->create(['amount' => $amount, 'paid_on' => '2028-02-29'])->fresh();
        $this->assertSame($amount, $report->amount);
        $this->assertSame($formatted, $report->formattedAmount());
        $this->assertSame('2028-02-29', $report->toArray()['paid_on']);
    }

    #[DataProvider('models')]
    public function test_all_fields_are_guarded_and_event_times_are_utc(string $class, string $timeField): void
    {
        $record = $class::factory()->create([$timeField => '2026-10-27 00:15:00+05:30'])->fresh();
        foreach (array_keys($record->getAttributes()) as $field) {
            $this->assertFalse($record->isFillable($field));
        }
        $this->assertInstanceOf(CarbonImmutable::class, $record->{$timeField});
        $this->assertSame('2026-10-26 18:45:00', $record->getRawOriginal($timeField));
        $this->assertSame('UTC', $record->{$timeField}->timezoneName);
    }

    #[DataProvider('immutableFields')]
    public function test_history_cannot_be_rewritten_even_with_force_fill(string $class, string $field, mixed $value): void
    {
        $record = $class::factory()->create()->fresh();
        $before = $record->getAttributes();
        try {
            $record->forceFill([$field => $value])->save();
            $this->fail('Recorded payment history must remain immutable.');
        } catch (LogicException) {
            $this->assertSame($before, $record->fresh()->getAttributes());
        }
    }

    #[DataProvider('models')]
    public function test_history_cannot_be_deleted(string $class): void
    {
        $record = $class::factory()->create();
        try {
            $record->delete();
            $this->fail('Recorded payment history must be preserved.');
        } catch (LogicException) {
            $this->assertModelExists($record);
        }
    }

    #[DataProvider('uniqueRecords')]
    public function test_database_uniqueness(string $constraint): void
    {
        $report = PaymentReport::factory()->create();
        if ($constraint === 'acknowledgment') {
            PaymentAcknowledgment::factory()->create(['payment_report_id' => $report->id]);
        }
        $this->expectException(QueryException::class);
        match ($constraint) {
            'assignment' => PaymentReport::factory()->create(['project_assignment_id' => $report->project_assignment_id]),
            'submission' => PaymentReport::factory()->create(['submission_id' => $report->submission_id]),
            'acknowledgment' => PaymentAcknowledgment::factory()->create(['payment_report_id' => $report->id]),
        };
    }

    #[DataProvider('restrictedParents')]
    public function test_referenced_parent_deletion_or_identity_changes_are_restricted(string $parent, bool $update): void
    {
        $receipt = PaymentAcknowledgment::factory()->create();
        $report = $receipt->report;
        [$table, $id] = match ($parent) {
            'assignment' => ['project_assignments', $report->project_assignment_id],
            'reporter' => ['users', $report->reported_by],
            'report' => ['payment_reports', $report->id],
            'acknowledger' => ['users', $receipt->acknowledged_by],
        };
        $this->expectException(QueryException::class);
        $query = DB::table($table)->where('id', $id);
        $update ? $query->update(['id' => 999999]) : $query->delete();
    }

    #[DataProvider('unknownReferences')]
    public function test_unknown_foreign_keys_are_rejected(string $table, string $field): void
    {
        $receipt = PaymentAcknowledgment::factory()->create();
        $id = $table === 'payment_reports' ? $receipt->payment_report_id : $receipt->id;
        $this->expectException(QueryException::class);
        DB::table($table)->where('id', $id)->update([$field => 999999]);
    }

    public static function models(): array
    {
        return [[PaymentReport::class, 'reported_at'], [PaymentAcknowledgment::class, 'acknowledged_at']];
    }

    public static function amounts(): array
    {
        return [['1.01', 'LKR 1.01'], ['1234567.40', 'LKR 1,234,567.40'], ['9999999999.99', 'LKR 9,999,999,999.99']];
    }

    public static function immutableFields(): array
    {
        return [
            [PaymentReport::class, 'amount', '2.00'], [PaymentReport::class, 'currency', 'USD'],
            [PaymentReport::class, 'method', PaymentMethod::Cash], [PaymentReport::class, 'paid_on', '2000-01-01'],
            [PaymentReport::class, 'reference', 'Changed'], [PaymentReport::class, 'note', 'Changed'],
            [PaymentReport::class, 'reported_at', '2000-01-01'], [PaymentReport::class, 'reported_by', 999999],
            [PaymentReport::class, 'project_assignment_id', 999999], [PaymentReport::class, 'submission_id', '00000000-0000-4000-8000-000000000000'],
            [PaymentAcknowledgment::class, 'note', 'Changed'], [PaymentAcknowledgment::class, 'acknowledged_at', '2000-01-01'],
            [PaymentAcknowledgment::class, 'acknowledged_by', 999999], [PaymentAcknowledgment::class, 'payment_report_id', 999999],
        ];
    }

    public static function uniqueRecords(): array
    {
        return [['assignment'], ['submission'], ['acknowledgment']];
    }

    public static function restrictedParents(): array
    {
        $cases = [];
        foreach (['assignment', 'reporter', 'report', 'acknowledger'] as $parent) {
            foreach ([false, true] as $update) {
                $cases[] = [$parent, $update];
            }
        }

        return $cases;
    }

    public static function unknownReferences(): array
    {
        return [['payment_reports', 'project_assignment_id'], ['payment_reports', 'reported_by'], ['payment_acknowledgments', 'payment_report_id'], ['payment_acknowledgments', 'acknowledged_by']];
    }
}
