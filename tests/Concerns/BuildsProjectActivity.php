<?php

namespace Tests\Concerns;

use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Actions\Disputes\OpenDispute;
use App\Actions\Disputes\ResolveDispute;
use App\Actions\Milestones\CompleteMilestone;
use App\Actions\Milestones\SaveMilestone;
use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Actions\Projects\CompleteProject;
use App\Actions\Projects\PublishProject;
use App\Actions\Proposals\AcceptProposal;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Enums\ProjectActivityEvent as Event;
use App\Enums\ProjectStatus;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

trait BuildsProjectActivity
{
    public static function activityEvents(): array
    {
        return array_map(fn ($event) => [$event], Event::cases());
    }

    /** Real authorized actions; factories establish prerequisites without fabricated activity. */
    protected function activityOperation(Event $event): callable
    {
        if ($event === Event::Published) {
            $project = Project::factory()->create(['deadline' => '2026-12-01']);
            $this->actingAs($project->client);

            return fn () => app(PublishProject::class)->publish($project->client, $project->id, true);
        }
        if ($event === Event::Assigned) {
            $project = Project::factory()->create(['status' => ProjectStatus::Open, 'published_at' => now()->subDay(), 'deadline' => '2026-12-01']);
            $offer = Proposal::factory()->create(['project_id' => $project->id, 'proposed_deadline' => '2026-11-10']);
            $this->actingAs($project->client);

            return fn () => app(AcceptProposal::class)->accept($project->id, $offer->id, true);
        }
        if (in_array($event, [Event::VersionApproved, Event::ChangesRequested], true)) {
            $version = DeliverableVersion::factory()->create(['submission_note' => 'Sensitive upload note']);
            $assignment = $version->deliverable->assignment;
            $this->actingAs($assignment->project->client);

            return fn () => $event === Event::VersionApproved
                ? app(ReviewDeliverableVersion::class)->approve($assignment->project_id, $version->deliverable_id, $version->id, ['confirmed' => true, 'remarks' => 'Sensitive review remarks'])
                : app(ReviewDeliverableVersion::class)->requestChanges($assignment->project_id, $version->deliverable_id, $version->id, ['remarks' => 'Sensitive review remarks']);
        }
        if ($event === Event::Completed) {
            $review = DeliverableReview::factory()->create();
            $assignment = $review->version->deliverable->assignment;
            $this->actingAs($assignment->project->client);

            return fn () => app(CompleteProject::class)->complete($assignment->project_id, ['confirmed' => true]);
        }
        if ($event === Event::ReceiptAcknowledged) {
            $report = PaymentReport::factory()->create();
            $this->actingAs($report->assignment->freelancer);

            return fn () => app(AcknowledgePayment::class)->acknowledge($report->assignment->project_id, $report->id, ['confirmed' => true, 'note' => 'Sensitive receipt note']);
        }
        if (in_array($event, [Event::DisputeResolved, Event::DisputeDismissed], true)) {
            $case = DisputeCase::factory()->create();
            $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

            return fn () => app(ResolveDispute::class)->resolve($case->id, ['decision' => $event === Event::DisputeResolved ? 'resolved' : 'dismissed', 'rationale' => 'Sensitive administrative reason', 'confirmed' => true]);
        }
        $assignment = ProjectAssignment::factory()->create(['agreed_deadline' => '2026-12-01']);
        $this->actingAs($assignment->project->client);
        $milestoneInput = ['title' => 'Sensitive milestone title', 'description' => 'Sensitive description', 'due_date' => '2026-11-10'];
        if (in_array($event, [Event::MilestoneCreated, Event::MilestoneEdited, Event::MilestoneCompleted], true)) {
            if ($event === Event::MilestoneCreated) {
                // Retry of creation has no request token: the intentional second creation is tested separately.
                return fn () => app(SaveMilestone::class)->save($assignment->project_id, null, $milestoneInput);
            }
            $milestone = app(SaveMilestone::class)->save($assignment->project_id, null, $milestoneInput);

            return $event === Event::MilestoneEdited
                ? fn () => app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, array_replace($milestoneInput, ['title' => 'Sensitive changed title']))
                : fn () => app(CompleteMilestone::class)->complete($assignment->project_id, $milestone->id, ['confirmed' => true, 'completion_note' => 'Sensitive completion note']);
        }
        if (in_array($event, [Event::TaskCreated, Event::TaskEdited, Event::TaskStatusChanged], true)) {
            $input = ['title' => 'Sensitive task title', 'description' => 'Sensitive task description', 'due_date' => '2026-11-10', 'milestone_id' => null];
            if ($event === Event::TaskCreated) {
                return fn () => app(SaveProjectTask::class)->save($assignment->project_id, null, $input);
            }
            $task = app(SaveProjectTask::class)->save($assignment->project_id, null, $input);

            return $event === Event::TaskEdited
                ? fn () => app(SaveProjectTask::class)->save($assignment->project_id, $task->id, array_replace($input, ['title' => 'Sensitive changed title']), $task->fresh()->details_version)
                : fn () => app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'done', 1);
        }
        $token = (string) Str::uuid();
        if ($event === Event::PaymentReported) {
            return fn () => app(ReportPayment::class)->report($assignment->project_id, ['confirmed' => true, 'method' => 'cash', 'paid_on' => now('Asia/Colombo')->toDateString(), 'reference' => 'Sensitive payment reference', 'note' => 'Sensitive payment note'], $token);
        }
        if ($event === Event::DisputeOpened) {
            return fn () => app(OpenDispute::class)->open($assignment->project_id, ['title' => 'Sensitive case title', 'reason' => 'Sensitive dispute reason', 'confirmed' => true], $token);
        }
        $this->actingAs($assignment->freelancer);

        return fn () => app(SubmitDeliverable::class)->submit($assignment->project_id, null, ['title' => 'Sensitive deliverable title', 'submission_note' => 'Sensitive upload note'], UploadedFile::fake()->createWithContent('work.txt', 'Sensitive original file'), $token);
    }
}
