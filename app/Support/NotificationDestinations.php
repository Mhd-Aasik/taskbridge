<?php

namespace App\Support;

use App\Enums\NotificationEvent;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\InAppNotification;
use App\Models\PaymentReport;
use App\Models\ProjectMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class NotificationDestinations
{
    public static function forNotification(User $user, InAppNotification $notification): ?string
    {
        if (! Gate::forUser($user)->allows('view', $notification)) {
            return null;
        }
        $event = $notification->event();
        $id = $notification->data['record_id'] ?? null;
        if (! $event || ! is_int($id) || $id < 1 || $notification->event_key !== $event->value.':'.$id) {
            return null;
        }

        return self::forEvent($user, $event, $id);
    }

    public static function forEvent(User $user, NotificationEvent $event, int $id): ?string
    {
        $record = $event->modelClass()::on($user->getConnectionName())->find($id);
        if (! $record) {
            return null;
        }

        return self::forRecord($user, $event, $record);
    }

    private static function forRecord(User $user, NotificationEvent $event, Model $record): ?string
    {
        $gate = Gate::forUser($user);
        if ($event === NotificationEvent::ProposalSubmitted) {
            $project = $record->project;

            return $project && $gate->allows('review', [$record, $project]) ? route('client.projects.proposals.show', [$project, $record]) : null;
        }
        if (in_array($event, [NotificationEvent::ProposalAccepted, NotificationEvent::ProposalRejected], true)) {
            return $gate->allows('view', $record) ? route('freelancer.proposals.show', $record) : null;
        }
        if (in_array($event, [NotificationEvent::VersionSubmitted, NotificationEvent::VersionApproved, NotificationEvent::VersionChangesRequested], true)) {
            $version = $record instanceof DeliverableVersion ? $record : $record->version;
            $deliverable = $version?->deliverable;
            $assignment = $deliverable?->assignment;
            $project = $assignment?->project;

            return $project && $gate->allows('view', [$version, $deliverable, $assignment, $project]) ? route('projects.deliverables.show', [$project, $deliverable]) : null;
        }
        if (in_array($event, [NotificationEvent::MilestoneCompleted, NotificationEvent::ProjectCompleted], true)) {
            $project = $event === NotificationEvent::ProjectCompleted ? $record : $record->assignment?->project;
            $assignment = $project?->assignment;

            return $assignment && $gate->allows('viewPlan', [$assignment, $project]) ? route($event === NotificationEvent::ProjectCompleted ? 'projects.completion' : 'projects.plan', $project) : null;
        }
        if (in_array($event, [NotificationEvent::PaymentReported, NotificationEvent::ReceiptAcknowledged], true)) {
            $report = $record instanceof PaymentReport ? $record : $record->report;
            $assignment = $report?->assignment;
            $project = $assignment?->project;

            return $project && $gate->allows('view', [$report, $assignment, $project]) ? route('projects.payment', $project) : null;
        }
        if (in_array($event, [NotificationEvent::DisputeOpened, NotificationEvent::DisputeStatementAdded, NotificationEvent::DisputeResolved, NotificationEvent::DisputeDismissed], true)) {
            $dispute = $record instanceof DisputeCase ? $record : $record->dispute;
            $assignment = $dispute?->assignment;
            $project = $assignment?->project;
            if (! $project) {
                return null;
            }
            $ability = $user->isAdmin() ? 'viewAdmin' : 'viewParticipant';

            return $gate->allows($ability, [$dispute, $assignment, $project]) ? route($user->isAdmin() ? 'admin.disputes.show' : 'disputes.show', $dispute) : null;
        }
        $assignment = $record->assignment;
        $project = $assignment?->project;

        return $project && $gate->allows('viewConversation', [ProjectMessage::class, $assignment, $project]) ? route('projects.messages', $project) : null;
    }
}
