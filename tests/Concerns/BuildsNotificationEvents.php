<?php

namespace Tests\Concerns;

use App\Enums\NotificationEvent;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\DisputeStatement;
use App\Models\Milestone;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use App\Models\Proposal;
use App\Services\ProjectNotifications;
use Illuminate\Support\Facades\DB;

trait BuildsNotificationEvents
{
    /** Domain factories never create alerts. Explicit service calls below are test-only event fixtures. */
    protected function notificationEvent(NotificationEvent $event): array
    {
        if (in_array($event, [NotificationEvent::ProposalSubmitted, NotificationEvent::ProposalAccepted, NotificationEvent::ProposalRejected], true)) {
            $record = Proposal::factory()->create(['status' => match ($event) {
                NotificationEvent::ProposalAccepted => ProposalStatus::Accepted, NotificationEvent::ProposalRejected => ProposalStatus::Rejected, default => ProposalStatus::Submitted
            }]);

            return [$record, $event === NotificationEvent::ProposalSubmitted ? $record->freelancer : $record->project->client,
                [$event === NotificationEvent::ProposalSubmitted ? $record->project->client : $record->freelancer]];
        }
        if ($event === NotificationEvent::VersionSubmitted) {
            $record = DeliverableVersion::factory()->create();

            return [$record, $record->uploader, [$record->deliverable->assignment->project->client]];
        }
        if (in_array($event, [NotificationEvent::VersionApproved, NotificationEvent::VersionChangesRequested], true)) {
            $record = DeliverableReview::factory()->create(['decision' => $event === NotificationEvent::VersionApproved ? 'approved' : 'changes_requested']);

            return [$record, $record->reviewer, [$record->version->deliverable->assignment->freelancer]];
        }
        if ($event === NotificationEvent::MilestoneCompleted) {
            $record = Milestone::factory()->completed()->create();

            return [$record, $record->completer, [$record->assignment->freelancer]];
        }
        if ($event === NotificationEvent::ProjectCompleted) {
            $assignment = ProjectAssignment::factory()->create();
            $record = $assignment->project;
            $record->forceFill(['status' => ProjectStatus::Completed, 'completed_at' => now(), 'completed_by' => $record->client_id])->save();

            return [$record, $record->client, [$assignment->freelancer]];
        }
        if ($event === NotificationEvent::PaymentReported) {
            $record = PaymentReport::factory()->create();

            return [$record, $record->reporter, [$record->assignment->freelancer]];
        }
        if ($event === NotificationEvent::ReceiptAcknowledged) {
            $record = PaymentAcknowledgment::factory()->create();

            return [$record, $record->acknowledger, [$record->report->assignment->project->client]];
        }
        if ($event === NotificationEvent::DisputeOpened) {
            $record = DisputeCase::factory()->create();

            return [$record, $record->opener, [$record->assignment->freelancer]];
        }
        if ($event === NotificationEvent::DisputeStatementAdded) {
            $record = DisputeStatement::factory()->create();

            return [$record, $record->author, [$record->dispute->assignment->freelancer]];
        }
        if (in_array($event, [NotificationEvent::DisputeResolved, NotificationEvent::DisputeDismissed], true)) {
            $record = DisputeResolution::factory()->create(['decision' => $event === NotificationEvent::DisputeResolved ? 'resolved' : 'dismissed']);

            return [$record, $record->decider, [$record->dispute->assignment->project->client, $record->dispute->assignment->freelancer]];
        }
        $record = ProjectMessage::factory()->create();

        return [$record, $record->sender, [$record->assignment->freelancer]];
    }

    protected function emitEvent(NotificationEvent $event, $record): array
    {
        return DB::transaction(fn () => app(ProjectNotifications::class)->record($event, $record));
    }

    public static function notificationEvents(): array
    {
        $cases = [];
        foreach (NotificationEvent::cases() as $event) {
            $cases[$event->value] = [$event];
        }

        return $cases;
    }
}
