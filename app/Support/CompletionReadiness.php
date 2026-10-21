<?php

namespace App\Support;

use App\Enums\DeliverableReviewDecision;
use App\Enums\ProjectTaskStatus;
use App\Models\Milestone;
use App\Models\ProjectAssignment;

class CompletionReadiness
{
    public static function milestoneCounts(): array
    {
        return [
            'tasks',
            'tasks as completed_tasks_count' => fn ($query) => $query->where('status', ProjectTaskStatus::Done->value),
            'deliverables',
            'deliverables as approved_deliverables_count' => fn ($query) => $query->whereHas('latestVersion.review', fn ($reviews) => $reviews->where('decision', DeliverableReviewDecision::Approved->value)),
        ];
    }

    public static function forMilestone(Milestone $milestone, bool $reload = true): array
    {
        if ($reload) {
            $milestone->loadCount(self::milestoneCounts());
        }
        $tasks = (int) $milestone->tasks_count;
        $deliverables = (int) $milestone->deliverables_count;
        $pending = $tasks - (int) $milestone->completed_tasks_count;
        $unapproved = $deliverables - (int) $milestone->approved_deliverables_count;
        $reasons = [];
        if ($pending) {
            $reasons[] = $pending.' unfinished task(s) must be marked done.';
        }
        if ($unapproved) {
            $reasons[] = $unapproved.' deliverable(s) need approval of their latest version.';
        }

        return ['ready' => $reasons === [], 'reasons' => $reasons, 'tasks' => $tasks, 'deliverables' => $deliverables, 'requires_note' => $tasks === 0 && $deliverables === 0];
    }

    public static function forProject(ProjectAssignment $assignment): array
    {
        $milestones = $assignment->milestones()->count();
        $incomplete = $assignment->milestones()->whereNull('completed_at')->count();
        $tasks = $assignment->tasks()->count();
        $pending = $assignment->tasks()->where('status', '!=', ProjectTaskStatus::Done->value)->count();
        $reviews = DeliverableReviewSummary::forAssignment($assignment);
        $deliverables = $assignment->deliverables()->count();
        $unapproved = $deliverables - $reviews['approved'];
        $reasons = [];
        if ($incomplete) {
            $reasons[] = $incomplete.' milestone(s) must be explicitly completed by the Client.';
        }
        if ($pending) {
            $reasons[] = $pending.' unfinished task(s), including ungrouped tasks, must be marked done.';
        }
        if ($deliverables === 0) {
            $reasons[] = 'Submit at least one deliverable and approve its latest version.';
        } elseif ($unapproved) {
            $reasons[] = $unapproved.' deliverable(s) need approval of their latest version.';
        }

        return ['ready' => $reasons === [], 'reasons' => $reasons, 'milestones' => $milestones, 'completed_milestones' => $milestones - $incomplete, 'tasks' => $tasks, 'completed_tasks' => $tasks - $pending, 'deliverables' => $deliverables, 'approved_deliverables' => $reviews['approved']];
    }
}
