<?php

namespace App\Actions\Payments;

use App\Enums\NotificationEvent;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Models\PaymentReport;
use App\Services\ProjectNotifications;
use App\Support\PaymentRules;
use App\Support\ProjectPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReportPayment
{
    public function report(int $projectId, array $input, string $submissionId): PaymentReport
    {
        $submissionId = Str::lower($submissionId);
        try {
            return $this->persist($projectId, $input, $submissionId);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            $known = preg_match('/\bpayment_reports_(project_assignment_id|submission_id)_unique\b/', $message)
                || preg_match('/UNIQUE constraint failed: payment_reports\.(project_assignment_id|submission_id)\b/', $message);
            if (! $known) {
                throw $exception;
            }

            return $this->persist($projectId, $input, $submissionId, $exception);
        }
    }

    private function persist(int $projectId, array $input, string $submissionId, ?UniqueConstraintViolationException $conflict = null): PaymentReport
    {
        return DB::transaction(function () use ($projectId, $input, $submissionId, $conflict): PaymentReport {
            [$user, $project, $assignment] = ProjectPayment::context($projectId, lock: true);
            Gate::forUser($user)->authorize('create', [PaymentReport::class, $assignment, $project]);
            Validator::make(['submission_id' => $submissionId], ['submission_id' => ['required', 'uuid']])->validate();
            $details = PaymentRules::reportDetails($input);
            $existing = $assignment->paymentReport()->lockForUpdate()->first();
            if ($existing) {
                if ($existing->reported_by === $user->id && $existing->submission_id === $submissionId
                    && $existing->method === PaymentMethod::from($details['method'])
                    && $existing->paid_on->toDateString() === $details['paid_on']
                    && $existing->reference === $details['reference'] && $existing->note === $details['note']) {
                    return $existing;
                }
                throw ValidationException::withMessages(['payment' => 'A full payment report is already recorded for this assignment. Its details cannot be overwritten. Review the existing report.']);
            }
            // UUIDs are global; never reveal a report belonging to another assignment.
            if (PaymentReport::query()->where('submission_id', $submissionId)->exists()) {
                throw ValidationException::withMessages(['payment' => 'This report identifier has already been used. Reload the payment page before trying again.']);
            }
            if ($conflict) {
                throw $conflict;
            }
            if (! in_array($project->status, [ProjectStatus::InProgress, ProjectStatus::Completed], true)) {
                throw ValidationException::withMessages(['payment' => 'A new payment report requires an assigned project that is in progress or completed.']);
            }
            $amount = PaymentRules::agreedAmount($assignment, $details['paid_on']);
            $report = new PaymentReport;
            $report->assignment()->associate($assignment);
            $report->amount = $amount;
            $report->currency = 'LKR';
            $report->method = PaymentMethod::from($details['method']);
            $report->paid_on = $details['paid_on'];
            $report->reference = $details['reference'];
            $report->note = $details['note'];
            $report->reporter()->associate($user);
            $report->reported_at = CarbonImmutable::now('UTC');
            $report->submission_id = $submissionId;
            if (! $report->save()) {
                throw new RuntimeException('The payment report could not be recorded.');
            }

            app(ProjectNotifications::class)->record(NotificationEvent::PaymentReported, $report);

            return $report;
        }, 3);
    }
}
