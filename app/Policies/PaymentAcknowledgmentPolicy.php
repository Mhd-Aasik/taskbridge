<?php

namespace App\Policies;

use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class PaymentAcknowledgmentPolicy
{
    public function create(User $user, PaymentReport $report, ProjectAssignment $assignment, Project $project): bool
    {
        return app(PaymentReportPolicy::class)->view($user, $report, $assignment, $project)
            && $user->isFreelancer() && $assignment->freelancer_id === $user->id;
    }
}
