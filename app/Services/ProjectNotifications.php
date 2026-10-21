<?php

namespace App\Services;

use App\Enums\DisputeStatementKind;
use App\Enums\NotificationEvent;
use App\Models\InAppNotification;
use App\Models\User;
use App\Support\NotificationDestinations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class ProjectNotifications
{
    /** Explicit business-action hook only: never use from renderers, observers, factories or backfills. */
    public function record(NotificationEvent $event, Model $origin): array
    {
        $connection = $origin->getConnection();
        if ($connection->transactionLevel() < 1 || ! $origin instanceof ($event->modelClass())) {
            throw new LogicException('Notifications require the originating domain transaction and matching record type.');
        }
        $record = $origin->newQuery()->findOrFail($origin->getKey());
        $valid = match ($event) {
            NotificationEvent::ProposalAccepted => $record->status->value === 'accepted',
            NotificationEvent::ProposalRejected => $record->status->value === 'rejected',
            NotificationEvent::VersionApproved => $record->decision->value === 'approved',
            NotificationEvent::VersionChangesRequested => $record->decision->value === 'changes_requested',
            NotificationEvent::MilestoneCompleted, NotificationEvent::ProjectCompleted => $record->isCompleted(),
            NotificationEvent::DisputeResolved => $record->decision->value === 'resolved',
            NotificationEvent::DisputeDismissed => $record->decision->value === 'dismissed',
            default => true,
        };
        if (! $valid) {
            throw new LogicException('The notification event must match the saved domain outcome.');
        }
        [$actor, $candidates] = $this->audience($event, $record);
        if (! $actor) {
            throw new LogicException('An event must have a persisted domain actor.');
        }
        $result = [];
        foreach ($candidates as $id => $role) {
            if ((int) $id === (int) $actor) {
                continue;
            }
            $recipient = User::on($connection->getName())->find($id);
            if (! $recipient || $recipient->role !== $role || ! NotificationDestinations::forEvent($recipient, $event, $record->id)) {
                continue;
            }
            if ($role === User::ROLE_ADMIN) {
                $dispute = $event === NotificationEvent::DisputeOpened ? $record : $record->dispute;
                if (! Gate::forUser($recipient)->allows('resolve', [$dispute, $dispute->assignment, $dispute->assignment->project])) {
                    continue;
                }
            }
            $result[] = $this->persist($event, $record, $recipient);
        }

        return $result;
    }

    private function audience(NotificationEvent $event, Model $record): array
    {
        if ($event === NotificationEvent::ProjectFeedbackSubmitted) {
            return [$record->reviewer_id, [$record->reviewee_id => $record->reviewee_id === $record->assignment->freelancer_id ? User::ROLE_FREELANCER : User::ROLE_CLIENT]];
        }
        if (in_array($event, [NotificationEvent::ProposalSubmitted, NotificationEvent::ProposalAccepted, NotificationEvent::ProposalRejected], true)) {
            return $event === NotificationEvent::ProposalSubmitted
                ? [$record->freelancer_id, [$record->project->client_id => User::ROLE_CLIENT]]
                : [$record->project->client_id, [$record->freelancer_id => User::ROLE_FREELANCER]];
        }
        if ($event === NotificationEvent::VersionSubmitted) {
            return [$record->uploaded_by, [$record->deliverable->assignment->project->client_id => User::ROLE_CLIENT]];
        }
        if (in_array($event, [NotificationEvent::VersionApproved, NotificationEvent::VersionChangesRequested], true)) {
            return [$record->reviewed_by, [$record->version->deliverable->assignment->freelancer_id => User::ROLE_FREELANCER]];
        }
        if ($event === NotificationEvent::MilestoneCompleted) {
            return [$record->completed_by, [$record->assignment->freelancer_id => User::ROLE_FREELANCER]];
        }
        if ($event === NotificationEvent::ProjectCompleted) {
            return [$record->completed_by, [$record->assignment->freelancer_id => User::ROLE_FREELANCER]];
        }
        if ($event === NotificationEvent::PaymentReported) {
            return [$record->reported_by, [$record->assignment->freelancer_id => User::ROLE_FREELANCER]];
        }
        if ($event === NotificationEvent::ReceiptAcknowledged) {
            return [$record->acknowledged_by, [$record->report->assignment->project->client_id => User::ROLE_CLIENT]];
        }
        if ($event === NotificationEvent::ProjectMessageSent) {
            $assignment = $record->assignment;

            return [$record->sender_id, $record->sender_id === $assignment->project->client_id
                ? [$assignment->freelancer_id => User::ROLE_FREELANCER] : [$assignment->project->client_id => User::ROLE_CLIENT]];
        }
        $dispute = $event === NotificationEvent::DisputeOpened ? $record : $record->dispute;
        $assignment = $dispute->assignment;
        $candidates = [$assignment->project->client_id => User::ROLE_CLIENT, $assignment->freelancer_id => User::ROLE_FREELANCER];
        if ($event === NotificationEvent::DisputeOpened) {
            $adminIds = User::on($record->getConnectionName())->where('role', User::ROLE_ADMIN)->pluck('id');
            $actor = $record->opened_by;
        } elseif ($event === NotificationEvent::DisputeStatementAdded) {
            // Notify only already participating clarification authors, not every Admin on each reply.
            $adminIds = $dispute->statements()->where('kind', DisputeStatementKind::Clarification->value)->pluck('submitted_by');
            $actor = $record->submitted_by;
        } else {
            $adminIds = collect();
            $actor = $record->decided_by;
        }
        foreach ($adminIds as $id) {
            if (! isset($candidates[$id])) {
                $candidates[$id] = User::ROLE_ADMIN;
            }
        }

        return [$actor, $candidates];
    }

    private function persist(NotificationEvent $event, Model $record, User $recipient): InAppNotification
    {
        $connection = $record->getConnection();
        $key = $event->value.':'.$record->id;
        $existing = fn () => InAppNotification::on($connection->getName())->where('notifiable_type', $recipient->getMorphClass())->where('notifiable_id', $recipient->id)->where('event_key', $key)->lockForUpdate()->first();
        if ($notification = $existing()) {
            return $this->verifyExisting($notification, $event, $record);
        }
        try {
            return $connection->transaction(function () use ($connection, $event, $record, $recipient, $key): InAppNotification {
                $notification = new InAppNotification;
                $notification->setConnection($connection->getName());
                $notification->id = (string) Str::uuid();
                $notification->type = $event->value;
                $notification->notifiable_type = $recipient->getMorphClass();
                $notification->notifiable_id = $recipient->id;
                $notification->event_key = $key;
                $notification->data = ['record_id' => (int) $record->id];
                if (! $notification->save()) {
                    throw new RuntimeException('The notification could not be recorded.');
                }

                return $notification;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            $known = str_contains($message, 'notifications_recipient_event_unique') || str_contains($message, 'UNIQUE constraint failed: notifications.notifiable_type, notifications.notifiable_id, notifications.event_key');
            if (! $known || ! ($winner = $existing())) {
                throw $exception;
            }

            return $this->verifyExisting($winner, $event, $record);
        }
    }

    private function verifyExisting(InAppNotification $notification, NotificationEvent $event, Model $record): InAppNotification
    {
        if ($notification->type !== $event->value || $notification->data !== ['record_id' => (int) $record->id]) {
            throw new LogicException('A recipient/event key must not identify different notification data.');
        }

        return $notification;
    }
}
