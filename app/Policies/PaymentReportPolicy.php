<?php

namespace App\Policies;

use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class PaymentReportPolicy
{
    public function viewAny(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $assignment->project_id === $project->id && (
            ($user->isClient() && $project->client_id === $user->id)
            || ($user->isFreelancer() && $assignment->freelancer_id === $user->id)
        );
    }

    public function view(User $user, PaymentReport $report, ProjectAssignment $assignment, Project $project): bool
    {
        return $report->project_assignment_id === $assignment->id && $this->viewAny($user, $assignment, $project);
    }

    public function create(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        // New-state eligibility is checked by the action; historical exact retries remain read-only.
        return $this->viewAny($user, $assignment, $project) && $user->isClient() && $project->client_id === $user->id;
    }
}
