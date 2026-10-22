<?php

namespace App\Services;

use App\Enums\DeliverableReviewDecision;
use App\Enums\DisputeDecision;
use App\Enums\ProjectActivityEvent as Event;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Support\AccountAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use RuntimeException;

class ProjectActivities
{
    /** Explicit action hooks only: never observers, rendering, notifications or backfills. */
    public function record(Event $event, Model $origin, User $actor): ProjectActivity
    {
        $connection = $origin->getConnection();
        if ($connection->transactionLevel() < 1 || ! $origin instanceof ($event->modelClass())) {
            throw new LogicException('Activity requires its business transaction and allowlisted origin.');
        }
        $record = $origin->newQuery()->findOrFail($origin->getKey());
        $actor = User::on($connection->getName())->findOrFail($actor->id);
        AccountAccess::ensure($actor);
        $assignment = match ($event) {
            Event::Published => null,
            Event::Assigned => $record,
            Event::Completed => $record->assignment,
            Event::VersionSubmitted => $record->deliverable->assignment,
            Event::VersionApproved, Event::ChangesRequested => $record->version->deliverable->assignment,
            Event::ReceiptAcknowledged => $record->report->assignment,
            Event::DisputeResolved, Event::DisputeDismissed => $record->dispute->assignment,
            default => $record->assignment,
        };
        $project = $record instanceof Project ? $record : $assignment?->project;
        if (! $project || ($event !== Event::Published && ! $assignment instanceof ProjectAssignment)) {
            throw new LogicException('Activity requires a persisted project and applicable assignment.');
        }
        $client = $actor->isClient() && $project->client_id === $actor->id;
        $freelancer = $actor->isFreelancer() && $assignment?->freelancer_id === $actor->id;
        $valid = match ($event) {
            Event::Published => $client && $record->status === ProjectStatus::Open && $record->published_at !== null,
            Event::Assigned => $client && $project->status === ProjectStatus::InProgress,
            Event::MilestoneCreated, Event::TaskCreated => $client && $record->created_by === $actor->id,
            Event::MilestoneEdited, Event::TaskEdited => $client,
            Event::MilestoneCompleted, Event::Completed => $client && $record->completed_by === $actor->id && $record->completed_at !== null,
            Event::TaskStatusChanged => $client || $freelancer,
            Event::VersionSubmitted => $freelancer && $record->uploaded_by === $actor->id,
            Event::VersionApproved, Event::ChangesRequested => $client && $record->reviewed_by === $actor->id
                && $record->decision === ($event === Event::VersionApproved ? DeliverableReviewDecision::Approved : DeliverableReviewDecision::ChangesRequested),
            Event::PaymentReported => $client && $record->reported_by === $actor->id,
            Event::ReceiptAcknowledged => $freelancer && $record->acknowledged_by === $actor->id,
            Event::DisputeOpened => ($client || $freelancer) && $record->opened_by === $actor->id,
            Event::DisputeResolved, Event::DisputeDismissed => $actor->isAdmin() && $record->decided_by === $actor->id
                && $record->decision === ($event === Event::DisputeResolved ? DisputeDecision::Resolved : DisputeDecision::Dismissed),
        };
        if (! $valid) {
            throw new LogicException('Activity does not match its saved outcome or authorized actor.');
        }
        $revision = match ($event) {
            Event::MilestoneEdited, Event::TaskEdited => $record->details_version,
            Event::TaskStatusChanged => $record->status_version,
            default => 0,
        };
        if (in_array($event, [Event::MilestoneEdited, Event::TaskEdited, Event::TaskStatusChanged], true) && $revision < 1) {
            throw new LogicException('A changed activity requires a persisted revision.');
        }
        $metadata = ['record_id' => (int) $record->id];
        if ($revision > 0) {
            $metadata['revision'] = $revision;
        }
        if ($event === Event::TaskStatusChanged) {
            $metadata['task_status'] = $record->status->value;
        }
        $occurred = match ($event) {
            Event::Published => $record->published_at,
            Event::Assigned => $record->assigned_at,
            Event::MilestoneCreated, Event::TaskCreated => $record->created_at,
            Event::MilestoneCompleted, Event::Completed => $record->completed_at,
            Event::VersionSubmitted => $record->submitted_at,
            Event::VersionApproved, Event::ChangesRequested => $record->reviewed_at,
            Event::PaymentReported => $record->reported_at,
            Event::ReceiptAcknowledged => $record->acknowledged_at,
            Event::DisputeOpened => $record->opened_at,
            Event::DisputeResolved, Event::DisputeDismissed => $record->decided_at,
            default => CarbonImmutable::now('UTC'),
        };
        $key = $event->value.':'.$record->id.':'.$revision;
        $query = ProjectActivity::on($connection->getName())->where('project_id', $project->id)->where('event_key', $key);
        $existing = $query->clone()->lockForUpdate()->first();
        if (! $existing) {
            try {
                $existing = $connection->transaction(function () use ($connection, $project, $assignment, $actor, $event, $key, $metadata, $occurred): ProjectActivity {
                    $activity = new ProjectActivity;
                    $activity->setConnection($connection->getName());
                    $activity->project_id = $project->id;
                    $activity->project_assignment_id = $assignment?->id;
                    $activity->actor_id = $actor->id;
                    $activity->event_type = $event;
                    $activity->event_key = $key;
                    $activity->metadata = $metadata;
                    $activity->occurred_at = $occurred;
                    if (! $activity->save()) {
                        throw new RuntimeException('Project activity could not be recorded.');
                    }

                    return $activity;
                });
            } catch (UniqueConstraintViolationException $exception) {
                $message = (string) ($exception->errorInfo[2] ?? '');
                if (! str_contains($message, 'project_activity_event_unique')
                    && ! str_contains($message, 'UNIQUE constraint failed: project_activities.project_id, project_activities.event_key')) {
                    throw $exception;
                }
                $existing = $query->clone()->lockForUpdate()->first() ?? throw $exception;
            }
        }
        // MySQL JSON objects may return keys in a different order; retain strict value/type checks.
        $storedMetadata = $existing->metadata;
        ksort($storedMetadata);
        ksort($metadata);
        if ($existing->actor_id !== $actor->id || $existing->project_assignment_id !== $assignment?->id
            || $existing->event_type !== $event || $storedMetadata !== $metadata) {
            throw new LogicException('An activity identity cannot be reused for another change.');
        }

        return $existing;
    }
}
