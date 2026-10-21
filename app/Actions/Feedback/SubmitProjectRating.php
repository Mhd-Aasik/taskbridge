<?php

namespace App\Actions\Feedback;

use App\Enums\NotificationEvent;
use App\Models\ProjectRating;
use App\Services\ProjectNotifications;
use App\Support\ProjectFeedback;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubmitProjectRating
{
    public function submit(int $projectId, array $input, string $submissionId): ProjectRating
    {
        try {
            return $this->persist($projectId, $input, $submissionId);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            $known = str_contains($message, 'project_ratings_assignment_reviewer_unique') || str_contains($message, 'project_ratings_submission_id_unique')
                || str_contains($message, 'UNIQUE constraint failed: project_ratings.project_assignment_id, project_ratings.reviewer_id') || str_contains($message, 'UNIQUE constraint failed: project_ratings.submission_id');
            if (! $known) {
                throw $exception;
            }

            return $this->persist($projectId, $input, $submissionId, $exception);
        }
    }

    private function persist(int $projectId, array $input, string $submissionId, ?UniqueConstraintViolationException $conflict = null): ProjectRating
    {
        return DB::transaction(function () use ($projectId, $input, $submissionId, $conflict): ProjectRating {
            [$user, $project, $assignment, $reviewee] = ProjectFeedback::context($projectId, lock: true);
            Validator::make(['submission_id' => $submissionId, 'confirmed' => $input['confirmed'] ?? false], ['submission_id' => ['required', 'uuid'], 'confirmed' => ['accepted']])->validate();
            $details = ProjectFeedback::details($input);
            $existing = $assignment->ratings()->where('reviewer_id', $user->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->submission_id === $submissionId && $existing->reviewee_id === $reviewee?->id && $existing->rating === $details['rating'] && $existing->feedback === $details['feedback']) {
                    return $existing;
                }
                throw ValidationException::withMessages(['feedback' => 'Your feedback has already been submitted. Its score, comments and submission time cannot be changed.']);
            }
            if (ProjectRating::query()->where('submission_id', $submissionId)->exists()) {
                throw ValidationException::withMessages(['feedback' => 'This submission identifier has already been used. Reload the feedback page.']);
            }
            if ($conflict) {
                throw $conflict;
            }
            // Use one server instant for eligibility and the immutable submission time.
            $submittedAt = CarbonImmutable::now('UTC');
            ProjectFeedback::ensureOpen($project, $submittedAt);
            abort_unless($reviewee, 403);
            Gate::forUser($user)->authorize('create', [ProjectRating::class, $assignment, $project, $reviewee, $submittedAt]);
            $rating = new ProjectRating;
            $rating->assignment()->associate($assignment);
            $rating->reviewer()->associate($user);
            $rating->reviewee()->associate($reviewee);
            $rating->rating = $details['rating'];
            $rating->feedback = $details['feedback'];
            $rating->submitted_at = $submittedAt;
            $rating->submission_id = $submissionId;
            if (! $rating->save()) {
                throw new RuntimeException('Your project feedback could not be recorded.');
            }
            app(ProjectNotifications::class)->record(NotificationEvent::ProjectFeedbackSubmitted, $rating);

            return $rating;
        }, 3);
    }
}
