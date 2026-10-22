<?php

namespace App\Actions\Disputes;

use App\Enums\NotificationEvent;
use App\Enums\ProjectActivityEvent;
use App\Models\ActiveDispute;
use App\Models\DisputeCase;
use App\Services\ProjectActivities;
use App\Services\ProjectNotifications;
use App\Support\DisputeContext;
use App\Support\DisputeRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OpenDispute
{
    public function open(int $projectId, array $input, string $submissionId): DisputeCase
    {
        $activeConflict = false;
        try {
            return $this->persist($projectId, $input, $submissionId, $activeConflict);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            $tokenConflict = str_contains($message, 'dispute_cases_submission_id_unique') || str_contains($message, 'UNIQUE constraint failed: dispute_cases.submission_id');
            if (! $activeConflict && ! $tokenConflict) {
                throw $exception;
            }

            return $this->persist($projectId, $input, $submissionId, $activeConflict, $exception);
        }
    }

    private function persist(int $projectId, array $input, string $submissionId, bool &$activeConflict, ?UniqueConstraintViolationException $conflict = null): DisputeCase
    {
        return DB::transaction(function () use ($projectId, $input, $submissionId, &$activeConflict, $conflict): DisputeCase {
            [$user, , $assignment] = DisputeContext::forProject($projectId, lock: true);
            $token = DisputeRules::token($submissionId);
            $details = DisputeRules::opening($input);
            if ($existing = $assignment->disputes()->where('submission_id', $token)->lockForUpdate()->first()) {
                if ($existing->opened_by === $user->id && $existing->title === $details['title'] && $existing->reason === $details['reason']) {
                    return $existing;
                }
                throw ValidationException::withMessages(['dispute' => 'This opening identifier already records a different case request. Its original title and reason cannot be changed.']);
            }
            if (DisputeCase::query()->where('submission_id', $token)->exists()) {
                throw ValidationException::withMessages(['dispute' => 'This opening identifier has already been used. Reload the opening form.']);
            }
            if ($active = $assignment->activeDispute()->lockForUpdate()->first()) {
                return $assignment->disputes()->findOrFail($active->dispute_case_id);
            }
            if ($conflict) {
                throw $conflict;
            }
            $dispute = new DisputeCase;
            $dispute->assignment()->associate($assignment);
            $dispute->opener()->associate($user);
            $dispute->title = $details['title'];
            $dispute->reason = $details['reason'];
            $dispute->opened_at = CarbonImmutable::now('UTC');
            $dispute->submission_id = $token;
            if (! $dispute->save()) {
                throw new RuntimeException('The dispute could not be opened.');
            }
            $active = new ActiveDispute;
            $active->project_assignment_id = $assignment->id;
            $active->dispute_case_id = $dispute->id;
            try {
                if (! $active->save()) {
                    throw new RuntimeException('The active dispute could not be recorded.');
                }
            } catch (UniqueConstraintViolationException $exception) {
                // A PRIMARY collision is recoverable only at this specific active-marker insert.
                $message = (string) ($exception->errorInfo[2] ?? '');
                $activeConflict = str_contains($message, 'active_disputes_dispute_case_id_unique')
                    || preg_match('/UNIQUE constraint failed: active_disputes\.(project_assignment_id|dispute_case_id)\b/', $message)
                    || preg_match('/for key [\x27\x60](?:active_disputes\.)?PRIMARY[\x27\x60]/i', $message);
                throw $exception;
            }

            app(ProjectNotifications::class)->record(NotificationEvent::DisputeOpened, $dispute);
            app(ProjectActivities::class)->record(ProjectActivityEvent::DisputeOpened, $dispute, $user);

            return $dispute;
        }, 3);
    }
}
