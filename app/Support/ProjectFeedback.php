<?php

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectRating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectFeedback
{
    /** Account(s) -> project -> assignment -> ratings, matching existing mutation lock order. */
    public static function context(int $projectId, bool $lock = false): array
    {
        $candidate = ProjectAssignment::query()->where('project_id', $projectId)->firstOrFail();
        $clientId = Project::query()->whereKey($projectId)->value('client_id');
        $accounts = User::query()->whereIn('id', [Auth::id(), $clientId, $candidate->freelancer_id])->orderBy('id');
        if ($lock) {
            $accounts->lockForUpdate();
        }
        $accounts = $accounts->get()->keyBy('id');
        $user = $accounts->get(Auth::id());
        abort_unless($user, 403);
        $projects = Project::query()->whereKey($projectId);
        $assignments = ProjectAssignment::query()->whereKey($candidate->id);
        if ($lock) {
            $projects->lockForUpdate();
            $assignments->lockForUpdate();
        }
        $project = $projects->firstOrFail();
        $assignment = $assignments->firstOrFail();
        Gate::forUser($user)->authorize('viewAny', [ProjectRating::class, $assignment, $project]);
        if ($project->client_id !== (int) $clientId || $assignment->freelancer_id !== $candidate->freelancer_id) {
            throw ValidationException::withMessages(['feedback' => 'The project participants changed. Reload before submitting feedback.']);
        }
        $otherId = $user->id === $project->client_id ? $assignment->freelancer_id : $project->client_id;

        return [$user, $project, $assignment, $accounts->get($otherId)];
    }

    public static function window(Project $project, ?CarbonImmutable $at = null): array
    {
        $start = $project->status === ProjectStatus::Completed && $project->completed_by === $project->client_id ? $project->completed_at : null;
        $end = $start?->addDays(14);
        $now = ($at ?? CarbonImmutable::now('UTC'))->utc();

        return ['start' => $start, 'end' => $end, 'open' => $start && $now->greaterThanOrEqualTo($start) && $now->lessThan($end), 'ended' => $end && $now->greaterThanOrEqualTo($end)];
    }

    public static function ensureOpen(Project $project, ?CarbonImmutable $at = null): void
    {
        $window = self::window($project, $at);
        if (! $window['start']) {
            throw ValidationException::withMessages(['feedback' => 'Feedback requires an explicitly completed project with its original completion time and actor.']);
        }
        if (! $window['open']) {
            throw ValidationException::withMessages(['feedback' => $window['ended'] ? 'The 14-day feedback window has closed. Existing feedback is preserved.' : 'The feedback period has not started yet.']);
        }
    }

    public static function details(array $input): array
    {
        $feedback = $input['feedback'] ?? null;
        if (is_string($feedback)) {
            $feedback = Str::trim($feedback);
            $feedback = $feedback === '' ? null : $feedback;
        }
        $details = Validator::make(['rating' => $input['rating'] ?? null, 'feedback' => $feedback], [
            'rating' => ['bail', 'required', 'integer', 'between:1,5', function ($attribute, $value, $fail): void {
                if (! is_int($value) && (! is_string($value) || ! preg_match('/\A[1-5]\z/', $value))) {
                    $fail('Choose a whole-number rating from 1 to 5.');
                }
            }],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $details['rating'] = (int) $details['rating'];

        return $details;
    }
}
