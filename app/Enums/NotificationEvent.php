<?php

namespace App\Enums;

use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\DisputeStatement;
use App\Models\Milestone;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectMessage;
use App\Models\ProjectRating;
use App\Models\Proposal;

enum NotificationEvent: string
{
    case ProposalSubmitted = 'proposal_submitted';
    case ProposalAccepted = 'proposal_accepted';
    case ProposalRejected = 'proposal_rejected';
    case VersionSubmitted = 'version_submitted';
    case VersionApproved = 'version_approved';
    case VersionChangesRequested = 'version_changes_requested';
    case MilestoneCompleted = 'milestone_completed';
    case ProjectCompleted = 'project_completed';
    case PaymentReported = 'payment_reported';
    case ReceiptAcknowledged = 'receipt_acknowledged';
    case DisputeOpened = 'dispute_opened';
    case DisputeStatementAdded = 'dispute_statement_added';
    case DisputeResolved = 'dispute_resolved';
    case DisputeDismissed = 'dispute_dismissed';
    case ProjectMessageSent = 'project_message_sent';
    case ProjectFeedbackSubmitted = 'project_feedback_submitted';

    public function label(): string
    {
        return match ($this) {
            self::ProposalSubmitted => 'A new proposal was submitted to your project.',
            self::ProposalAccepted => 'Your proposal was accepted.',
            self::ProposalRejected => 'Another proposal was selected for this project.',
            self::VersionSubmitted => 'A new deliverable version is ready for your review.',
            self::VersionApproved => 'A deliverable version was approved.',
            self::VersionChangesRequested => 'Changes were requested on a deliverable version.',
            self::MilestoneCompleted => 'A project milestone was completed.',
            self::ProjectCompleted => 'Your assigned project was completed.',
            self::PaymentReported => 'The Client reported an external payment.',
            self::ReceiptAcknowledged => 'The Freelancer acknowledged payment receipt.',
            self::DisputeOpened => 'A private project dispute was opened.',
            self::DisputeStatementAdded => 'A statement was added to a private project dispute.',
            self::DisputeResolved => 'An Admin resolved a project dispute.',
            self::DisputeDismissed => 'An Admin dismissed a project dispute.',
            self::ProjectMessageSent => 'You have a new project message.',
            self::ProjectFeedbackSubmitted => 'Project feedback was submitted. Your private feedback page explains when it becomes shared.',
        };
    }

    public function modelClass(): string
    {
        return match ($this) {
            self::ProposalSubmitted, self::ProposalAccepted, self::ProposalRejected => Proposal::class,
            self::VersionSubmitted => DeliverableVersion::class,
            self::VersionApproved, self::VersionChangesRequested => DeliverableReview::class,
            self::MilestoneCompleted => Milestone::class,
            self::ProjectCompleted => Project::class,
            self::PaymentReported => PaymentReport::class,
            self::ReceiptAcknowledged => PaymentAcknowledgment::class,
            self::DisputeOpened => DisputeCase::class,
            self::DisputeStatementAdded => DisputeStatement::class,
            self::DisputeResolved, self::DisputeDismissed => DisputeResolution::class,
            self::ProjectMessageSent => ProjectMessage::class,
            self::ProjectFeedbackSubmitted => ProjectRating::class,
        };
    }
}
