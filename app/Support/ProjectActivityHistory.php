<?php

namespace App\Support;

use App\Enums\ProjectActivityEvent as Event;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ProjectActivityHistory
{
    /** Authorization precedes both counting and retrieving activity. No counterparty suspension gate. */
    public static function query(User $user, Project $project, ?ProjectAssignment $assignment): Builder
    {
        Gate::forUser($user)->authorize('viewAny', [ProjectActivity::class, $project, $assignment]);
        $query = $project->activities()->getQuery();
        if ($user->isFreelancer()) {
            // Null assignment is deliberately Client-only, including after later assignment.
            $query->where('project_assignment_id', $assignment->id)->where('occurred_at', '>=', $assignment->assigned_at);
        }

        return $query;
    }

    /** Project-level destinations use already-authorized context, without per-row database queries. */
    public static function destination(User $user, Event $event, Project $project, ?ProjectAssignment $assignment): ?string
    {
        $gate = Gate::forUser($user);
        if (! $gate->allows('viewAny', [ProjectActivity::class, $project, $assignment])) {
            return null;
        }
        if (in_array($event, [Event::Published, Event::Assigned], true)) {
            return $user->isClient() ? route('client.projects.show', $project)
                : ($assignment && $gate->allows('view', $assignment) ? route('freelancer.assignments.show', $assignment) : null);
        }
        if (! $assignment || ! $gate->allows('viewPlan', [$assignment, $project])) {
            return null;
        }

        return route(match ($event) {
            Event::VersionSubmitted, Event::VersionApproved, Event::ChangesRequested => 'projects.deliverables.index',
            Event::Completed => 'projects.completion',
            Event::PaymentReported, Event::ReceiptAcknowledged => 'projects.payment',
            Event::DisputeOpened, Event::DisputeResolved, Event::DisputeDismissed => 'disputes.index',
            default => 'projects.plan',
        }, in_array($event, [Event::DisputeOpened, Event::DisputeResolved, Event::DisputeDismissed], true) ? [] : [$project]);
    }
}
