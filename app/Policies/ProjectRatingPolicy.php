<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectRating;
use App\Models\User;
use App\Support\ProjectFeedback;
use Carbon\CarbonImmutable;

class ProjectRatingPolicy
{
    public function viewAny(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $assignment->project_id === $project->id && $project->client_id !== $assignment->freelancer_id
            && (($user->isClient() && $project->client_id === $user->id) || ($user->isFreelancer() && $assignment->freelancer_id === $user->id));
    }

    public function create(User $user, ProjectAssignment $assignment, Project $project, User $reviewee, ?CarbonImmutable $at = null): bool
    {
        return $this->viewAny($user, $assignment, $project) && ProjectFeedback::window($project, $at)['open']
            && ($user->isClient() ? $reviewee->id === $assignment->freelancer_id && $reviewee->isFreelancer() : $reviewee->id === $project->client_id && $reviewee->isClient());
    }

    public function view(User $user, ProjectRating $rating, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->viewAny($user, $assignment, $project) && $rating->project_assignment_id === $assignment->id
            && ($rating->reviewer_id === $user->id || ProjectRating::query()->whereKey($rating->id)->released()->exists());
    }
}
