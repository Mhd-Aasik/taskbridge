<?php

namespace App\Support;

use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

class DisputeConversation
{
    /** @return HasMany<ProjectMessage, ProjectAssignment> */
    public static function messages(int $caseId): HasMany
    {
        [$user, $project, $assignment, $dispute] = DisputeContext::forCase($caseId);
        Gate::forUser($user)->authorize('viewAdmin', [$dispute, $assignment, $project]);
        Gate::forUser($user)->authorize('viewForDispute', [ProjectMessage::class, $dispute, $assignment, $project]);

        return $assignment->messages()->with('sender:id,name');
    }
}
