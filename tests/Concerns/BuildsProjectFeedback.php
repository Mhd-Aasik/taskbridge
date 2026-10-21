<?php

namespace Tests\Concerns;

use App\Actions\Feedback\SubmitProjectRating;
use App\Enums\ProjectStatus;
use App\Models\ProjectAssignment;
use App\Models\ProjectRating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait BuildsProjectFeedback
{
    protected function completedAssignment(?CarbonImmutable $at = null): ProjectAssignment
    {
        $assignment = ProjectAssignment::factory()->create();
        $assignment->project->forceFill(['status' => ProjectStatus::Completed, 'completed_at' => $at ?? CarbonImmutable::now('UTC')->subDay(), 'completed_by' => $assignment->project->client_id])->save();

        return $assignment->fresh()->load(['project.client', 'freelancer']);
    }

    protected function feedbackFrom(ProjectAssignment $assignment, bool $client = true, array $attributes = []): ProjectRating
    {
        return ProjectRating::factory()->create(array_merge(['project_assignment_id' => $assignment->id,
            'reviewer_id' => $client ? $assignment->project->client_id : $assignment->freelancer_id,
            'reviewee_id' => $client ? $assignment->freelancer_id : $assignment->project->client_id,
            'rating' => 4, 'feedback' => 'Private feedback for this collaboration.'], $attributes))->fresh();
    }

    protected function submitFeedback(ProjectAssignment $assignment, User $user, array $input = [], ?string $token = null): ProjectRating
    {
        $this->actingAs($user);

        return app(SubmitProjectRating::class)->submit($assignment->project_id, array_merge(['rating' => 4, 'feedback' => 'Constructive project feedback.', 'confirmed' => true], $input), $token ?? (string) Str::uuid())->fresh();
    }
}
