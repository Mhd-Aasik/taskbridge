<?php

namespace App\Policies;

use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class DeliverableVersionPolicy
{
    public function view(User $user, DeliverableVersion $version, Deliverable $deliverable, ProjectAssignment $assignment, Project $project): bool
    {
        return $version->deliverable_id === $deliverable->id
            && app(DeliverablePolicy::class)->view($user, $deliverable, $assignment, $project);
    }

    public function download(User $user, DeliverableVersion $version, Deliverable $deliverable, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->view($user, $version, $deliverable, $assignment, $project);
    }
}
