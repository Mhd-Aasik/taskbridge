<?php

namespace App\Actions\Payments;

use App\Enums\NotificationEvent;
use App\Models\PaymentAcknowledgment;
use App\Services\ProjectNotifications;
use App\Support\PaymentRules;
use App\Support\ProjectPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AcknowledgePayment
{
    public function acknowledge(int $projectId, int $reportId, array $input = []): PaymentAcknowledgment
    {
        try {
            return $this->persist($projectId, $reportId, $input);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            $known = str_contains($message, 'payment_acknowledgments_payment_report_id_unique')
                || str_contains($message, 'UNIQUE constraint failed: payment_acknowledgments.payment_report_id');
            if (! $known) {
                throw $exception;
            }

            return $this->persist($projectId, $reportId, $input, $exception);
        }
    }

    private function persist(int $projectId, int $reportId, array $input, ?UniqueConstraintViolationException $conflict = null): PaymentAcknowledgment
    {
        return DB::transaction(function () use ($projectId, $reportId, $input, $conflict): PaymentAcknowledgment {
            [$user, $project, $assignment] = ProjectPayment::context($projectId, lock: true);
            abort_unless($user->isFreelancer() && $assignment->freelancer_id === $user->id, 403);
            // Same account -> project -> assignment order as work actions, then report -> acknowledgment.
            $report = $assignment->paymentReport()->whereKey($reportId)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('create', [PaymentAcknowledgment::class, $report, $assignment, $project]);
            $details = PaymentRules::receiptDetails($input);
            $existing = $report->acknowledgment()->lockForUpdate()->first();
            if ($existing) {
                if ($existing->acknowledged_by === $user->id && $existing->note === $details['note']) {
                    return $existing;
                }
                throw ValidationException::withMessages(['receipt' => 'Receipt is already acknowledged. Its actor, time and note cannot be changed.']);
            }
            if ($conflict) {
                throw $conflict;
            }
            $receipt = new PaymentAcknowledgment;
            $receipt->report()->associate($report);
            $receipt->acknowledger()->associate($user);
            $receipt->acknowledged_at = CarbonImmutable::now('UTC');
            $receipt->note = $details['note'];
            if (! $receipt->save()) {
                throw new RuntimeException('The receipt acknowledgment could not be recorded.');
            }

            app(ProjectNotifications::class)->record(NotificationEvent::ReceiptAcknowledged, $receipt);

            return $receipt;
        }, 3);
    }
}
