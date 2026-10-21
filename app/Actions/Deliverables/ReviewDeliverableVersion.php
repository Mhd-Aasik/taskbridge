<?php

namespace App\Actions\Deliverables;

use App\Enums\DeliverableReviewDecision;
use App\Enums\ProjectStatus;
use App\Models\DeliverableReview;
use App\Support\ProjectPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReviewDeliverableVersion
{
    public function approve(int $projectId, int $deliverableId, int $versionId, array $input = []): DeliverableReview
    {
        return $this->review($projectId, $deliverableId, $versionId, DeliverableReviewDecision::Approved, $input);
    }

    public function requestChanges(int $projectId, int $deliverableId, int $versionId, array $input = []): DeliverableReview
    {
        return $this->review($projectId, $deliverableId, $versionId, DeliverableReviewDecision::ChangesRequested, $input);
    }

    private function review(int $projectId, int $deliverableId, int $versionId, DeliverableReviewDecision $decision, array $input): DeliverableReview
    {
        try {
            return $this->persist($projectId, $deliverableId, $versionId, $decision, $input);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            $versionConflict = str_contains($message, 'deliverable_reviews_deliverable_version_id_unique')
                || str_contains($message, 'UNIQUE constraint failed: deliverable_reviews.deliverable_version_id');
            if (! $versionConflict) {
                throw $exception;
            }

            // Recover only the known version constraint, with fresh authorization and locks.
            return $this->persist($projectId, $deliverableId, $versionId, $decision, $input, $exception);
        }
    }

    private function persist(int $projectId, int $deliverableId, int $versionId, DeliverableReviewDecision $decision, array $input, ?UniqueConstraintViolationException $conflict = null): DeliverableReview
    {
        return DB::transaction(function () use ($projectId, $deliverableId, $versionId, $decision, $input, $conflict): DeliverableReview {
            [$user, $project, $assignment] = ProjectPlanning::context($projectId, lock: true);
            abort_unless($user->isClient() && $project->client_id === $user->id, 403);
            $deliverable = $assignment->deliverables()->findOrFail($deliverableId);
            // Match upload locking: account -> project -> assignment -> milestone -> deliverable -> version -> review.
            if ($deliverable->milestone_id !== null) {
                $assignment->milestones()->whereKey($deliverable->milestone_id)->lockForUpdate()->firstOrFail();
            }
            $deliverable = $assignment->deliverables()->lockForUpdate()->findOrFail($deliverableId);
            $version = $deliverable->versions()->lockForUpdate()->findOrFail($versionId);
            Gate::forUser($user)->authorize('review', [$version, $deliverable, $assignment, $project]);

            $remarks = $input['remarks'] ?? null;
            if (is_string($remarks)) {
                $remarks = Str::trim($remarks);
                $remarks = $remarks === '' ? null : $remarks;
            }
            $rules = ['remarks' => [$decision === DeliverableReviewDecision::ChangesRequested ? 'required' : 'nullable', 'string', 'max:5000', 'not_regex:/\A[\s\p{Z}\p{C}]*\z/u']];
            $values = ['remarks' => $remarks];
            if ($decision === DeliverableReviewDecision::Approved) {
                $rules['confirmed'] = ['required', 'accepted'];
                $values['confirmed'] = $input['confirmed'] ?? false;
            }
            $remarks = Validator::make($values, $rules, [
                'remarks.required' => 'Explain the changes needed before requesting a new version.',
                'remarks.not_regex' => 'Enter meaningful review remarks.',
                'confirmed.accepted' => 'Confirm that you want to approve this version.',
            ])->validate()['remarks'];

            $existing = $version->review()->lockForUpdate()->first();
            if ($existing) {
                // Exact retries remain safe after later revisions or project state changes.
                if ($existing->reviewed_by === $user->id && $existing->decision === $decision && $existing->remarks === $remarks) {
                    return $existing;
                }
                throw ValidationException::withMessages(['review' => 'This version already has a completed review. Its decision and remarks cannot be changed.']);
            }
            if ($conflict) {
                throw $conflict;
            }
            if ($project->status !== ProjectStatus::InProgress) {
                throw ValidationException::withMessages(['review' => 'New reviews are unavailable because this project is no longer in progress. Review history remains available.']);
            }
            $latest = $deliverable->versions()->lockForUpdate()->firstOrFail();
            if ($latest->id !== $version->id) {
                throw ValidationException::withMessages(['review' => 'A newer version has been submitted. Read the latest version before reviewing it. Your remarks have been kept.']);
            }

            $review = new DeliverableReview;
            $review->version()->associate($version);
            $review->reviewer()->associate($user);
            $review->decision = $decision;
            $review->remarks = $remarks;
            $review->reviewed_at = CarbonImmutable::now('UTC');
            if (! $review->save()) {
                throw new RuntimeException('The deliverable review could not be saved.');
            }

            return $review;
        });
    }
}
