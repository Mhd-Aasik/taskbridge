<?php

namespace App\Enums;

use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\Milestone;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;

enum ProjectActivityEvent: string
{
    case Published = 'project_published';
    case Assigned = 'project_assigned';
    case MilestoneCreated = 'milestone_created';
    case MilestoneEdited = 'milestone_edited';
    case MilestoneCompleted = 'milestone_completed';
    case TaskCreated = 'task_created';
    case TaskEdited = 'task_edited';
    case TaskStatusChanged = 'task_status_changed';
    case VersionSubmitted = 'version_submitted';
    case VersionApproved = 'version_approved';
    case ChangesRequested = 'version_changes_requested';
    case Completed = 'project_completed';
    case PaymentReported = 'payment_reported';
    case ReceiptAcknowledged = 'receipt_acknowledged';
    case DisputeOpened = 'dispute_opened';
    case DisputeResolved = 'dispute_resolved';
    case DisputeDismissed = 'dispute_dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Project published', self::Assigned => 'Freelancer assigned',
            self::MilestoneCreated => 'Milestone added', self::MilestoneEdited => 'Milestone updated', self::MilestoneCompleted => 'Milestone completed',
            self::TaskCreated => 'Task added', self::TaskEdited => 'Task updated', self::TaskStatusChanged => 'Task status changed',
            self::VersionSubmitted => 'Deliverable version submitted', self::VersionApproved => 'Deliverable version approved', self::ChangesRequested => 'Deliverable changes requested',
            self::Completed => 'Project completed', self::PaymentReported => 'External payment reported', self::ReceiptAcknowledged => 'Payment receipt acknowledged',
            self::DisputeOpened => 'Dispute opened', self::DisputeResolved => 'Dispute resolved', self::DisputeDismissed => 'Dispute dismissed',
        };
    }

    public function modelClass(): string
    {
        return match ($this) {
            self::Published, self::Completed => Project::class,
            self::Assigned => ProjectAssignment::class,
            self::MilestoneCreated, self::MilestoneEdited, self::MilestoneCompleted => Milestone::class,
            self::TaskCreated, self::TaskEdited, self::TaskStatusChanged => ProjectTask::class,
            self::VersionSubmitted => DeliverableVersion::class,
            self::VersionApproved, self::ChangesRequested => DeliverableReview::class,
            self::PaymentReported => PaymentReport::class, self::ReceiptAcknowledged => PaymentAcknowledgment::class,
            self::DisputeOpened => DisputeCase::class, self::DisputeResolved, self::DisputeDismissed => DisputeResolution::class,
        };
    }
}
