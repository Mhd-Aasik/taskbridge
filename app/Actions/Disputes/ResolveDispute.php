<?php

namespace App\Actions\Disputes;

use App\Enums\DisputeDecision;
use App\Enums\NotificationEvent;
use App\Models\DisputeResolution;
use App\Services\ProjectNotifications;
use App\Support\DisputeContext;
use App\Support\DisputeRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ResolveDispute
{
    public function resolve(int $caseId, array $input): DisputeResolution
    {
        try {
            return $this->persist($caseId, $input);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            if (! str_contains($message, 'dispute_resolutions_dispute_case_id_unique') && ! str_contains($message, 'UNIQUE constraint failed: dispute_resolutions.dispute_case_id')) {
                throw $exception;
            }

            return $this->persist($caseId, $input, $exception);
        }
    }

    private function persist(int $caseId, array $input, ?UniqueConstraintViolationException $conflict = null): DisputeResolution
    {
        return DB::transaction(function () use ($caseId, $input, $conflict): DisputeResolution {
            [$user, $project, $assignment, $dispute] = DisputeContext::forCase($caseId, lock: true);
            Gate::forUser($user)->authorize('resolve', [$dispute, $assignment, $project]);
            $details = DisputeRules::resolution($input);
            $decision = DisputeDecision::from($details['decision']);
            $active = $assignment->activeDispute()->lockForUpdate()->first();
            if ($existing = $dispute->resolution()->lockForUpdate()->first()) {
                if ($existing->decided_by === $user->id && $existing->decision === $decision && $existing->rationale === $details['rationale']) {
                    return $existing;
                }
                throw ValidationException::withMessages(['resolution' => 'This case already has an immutable Admin decision. Its actor, decision and rationale cannot be changed.']);
            }
            if ($conflict) {
                throw $conflict;
            }
            if ($active?->dispute_case_id !== $dispute->id) {
                throw ValidationException::withMessages(['resolution' => 'This case is no longer active. Reload its recorded history.']);
            }
            $resolution = new DisputeResolution;
            $resolution->dispute()->associate($dispute);
            $resolution->decider()->associate($user);
            $resolution->decision = $decision;
            $resolution->rationale = $details['rationale'];
            $resolution->decided_at = CarbonImmutable::now('UTC');
            if (! $resolution->save()) {
                throw new RuntimeException('The resolution could not be recorded.');
            }
            if (! $active->delete()) {
                throw new RuntimeException('The active case could not be closed.');
            }

            app(ProjectNotifications::class)->record($decision === DisputeDecision::Resolved ? NotificationEvent::DisputeResolved : NotificationEvent::DisputeDismissed, $resolution);

            return $resolution;
        }, 3);
    }
}
