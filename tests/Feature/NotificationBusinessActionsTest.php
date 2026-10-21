<?php

namespace Tests\Feature;

use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Actions\Disputes\OpenDispute;
use App\Actions\Disputes\ResolveDispute;
use App\Actions\Disputes\SubmitDisputeStatement;
use App\Actions\Feedback\SubmitProjectRating;
use App\Actions\Messages\SendProjectMessage;
use App\Actions\Milestones\CompleteMilestone;
use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Actions\Projects\CompleteProject;
use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Enums\NotificationEvent;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\InAppNotification;
use App\Models\Milestone;
use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsNotificationEvents;
use Tests\TestCase;

class NotificationBusinessActionsTest extends TestCase
{
    use BuildsNotificationEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'));
        Storage::fake('deliverables');
    }

    #[DataProvider('notificationEvents')]
    public function test_business_mutations_emit_once_and_exact_action_retries_preserve_alert_history(NotificationEvent $event): void
    {
        [$run, $recipientIds] = $this->operation($event);
        $this->assertDatabaseCount('notifications', 0);
        $run();
        $alerts = InAppNotification::where('type', $event->value)->get();
        $this->assertEqualsCanonicalizing($recipientIds, $alerts->pluck('notifiable_id')->all());
        $this->assertCount(count($recipientIds), $alerts);
        $before = [];
        foreach (InAppNotification::all() as $notification) {
            $before[$notification->id] = $notification->getAttributes();
        }
        $this->travel(5)->seconds();
        $run();
        $this->assertDatabaseCount('notifications', count($before));
        foreach (InAppNotification::all() as $notification) {
            $this->assertSame($before[$notification->id], $notification->getAttributes());
        }
    }

    #[DataProvider('notificationEvents')]
    public function test_notification_failure_rolls_back_the_originating_business_mutation(NotificationEvent $event): void
    {
        [$run] = $this->operation($event);
        $tables = ['projects', 'proposals', 'project_assignments', 'milestones', 'deliverables', 'deliverable_versions', 'deliverable_reviews', 'payment_reports', 'payment_acknowledgments', 'dispute_cases', 'active_disputes', 'dispute_statements', 'dispute_resolutions', 'project_messages', 'project_ratings'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy($table === 'active_disputes' ? 'project_assignment_id' : 'id')->get()->toJson();
        }
        InAppNotification::creating(fn () => false);
        try {
            $run();
            $this->fail('A failed alert cannot partially commit the originating action.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('notification could not be recorded', $exception->getMessage());
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy($table === 'active_disputes' ? 'project_assignment_id' : 'id')->get()->toJson(), $table);
        }
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], Storage::disk('deliverables')->allFiles());
    }

    public function test_assignment_notifies_only_newly_rejected_offers_and_the_selected_freelancer(): void
    {
        $project = $this->openProject();
        $selected = Proposal::factory()->create(['project_id' => $project->id, 'proposed_deadline' => '2026-11-10']);
        $others = Proposal::factory()->count(2)->create(['project_id' => $project->id]);
        $withdrawn = Proposal::factory()->create(['project_id' => $project->id, 'status' => ProposalStatus::Withdrawn]);
        $alreadyRejected = Proposal::factory()->create(['project_id' => $project->id, 'status' => ProposalStatus::Rejected]);
        $this->actingAs($project->client);
        app(AcceptProposal::class)->accept($project->id, $selected->id, true);
        $this->assertSame(1, $selected->freelancer->unreadNotifications()->where('type', NotificationEvent::ProposalAccepted->value)->count());
        foreach ($others as $other) {
            $this->assertSame(1, $other->freelancer->unreadNotifications()->where('type', NotificationEvent::ProposalRejected->value)->count());
        }
        $this->assertSame(0, $withdrawn->freelancer->notifications()->count());
        $this->assertSame(0, $alreadyRejected->freelancer->notifications()->count());
        $this->assertSame(0, $project->client->notifications()->count());
    }

    public function test_dispute_admin_recipients_are_policy_scoped_and_replies_notify_only_participating_reviewers(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($assignment->project->client);
        $case = app(OpenDispute::class)->open($assignment->project_id, ['title' => 'Review outcome', 'reason' => 'Please clarify this outcome.', 'confirmed' => true], (string) Str::uuid());
        $this->assertEqualsCanonicalizing([$assignment->freelancer_id, $admin->id, $otherAdmin->id], InAppNotification::pluck('notifiable_id')->all());
        $this->actingAs($admin);
        app(SubmitDisputeStatement::class)->submit($case->id, ['body' => 'Please clarify the evidence.'], (string) Str::uuid());
        $this->actingAs($assignment->freelancer);
        $reply = app(SubmitDisputeStatement::class)->submit($case->id, ['body' => 'Here is my clarification.'], (string) Str::uuid());
        $alerts = InAppNotification::where('event_key', NotificationEvent::DisputeStatementAdded->value.':'.$reply->id)->get();
        $this->assertEqualsCanonicalizing([$assignment->project->client_id, $admin->id], $alerts->pluck('notifiable_id')->all());
        $this->assertNotContains($otherAdmin->id, $alerts->pluck('notifiable_id')->all());
    }

    public function test_involved_admin_is_excluded_from_dispute_opening_alerts(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $involved = $assignment->freelancer;
        $involved->forceFill(['role' => User::ROLE_ADMIN])->save();
        $eligible = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($assignment->project->client);
        app(OpenDispute::class)->open($assignment->project_id, ['title' => 'Review outcome', 'reason' => 'Please review this assignment.', 'confirmed' => true], (string) Str::uuid());
        $this->assertSame([$eligible->id], InAppNotification::pluck('notifiable_id')->all());
    }

    private function openProject(): Project
    {
        return Project::factory()->create(['status' => ProjectStatus::Open, 'published_at' => now()->subDay(), 'deadline' => '2026-12-01']);
    }

    private function operation(NotificationEvent $event): array
    {
        if ($event === NotificationEvent::ProjectFeedbackSubmitted) {
            $assignment = ProjectAssignment::factory()->create();
            $assignment->project->forceFill(['status' => ProjectStatus::Completed, 'completed_at' => now('UTC'), 'completed_by' => $assignment->project->client_id])->save();
            $this->actingAs($assignment->project->client);
            $token = (string) Str::uuid();

            return [fn () => app(SubmitProjectRating::class)->submit($assignment->project_id, ['rating' => 4, 'feedback' => 'Private blind feedback', 'confirmed' => true], $token), [$assignment->freelancer_id]];
        }
        if ($event === NotificationEvent::ProposalSubmitted) {
            $project = $this->openProject();
            $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]));

            return [fn () => app(SubmitProposal::class)->submit($project->id, ['cover_letter' => 'Private proposal content', 'proposed_budget' => '12345.50', 'proposed_deadline' => '2026-11-10']), [$project->client_id]];
        }
        if (in_array($event, [NotificationEvent::ProposalAccepted, NotificationEvent::ProposalRejected], true)) {
            $project = $this->openProject();
            $selected = Proposal::factory()->create(['project_id' => $project->id, 'proposed_deadline' => '2026-11-10']);
            $other = $event === NotificationEvent::ProposalRejected ? Proposal::factory()->create(['project_id' => $project->id]) : null;
            $this->actingAs($project->client);

            return [fn () => app(AcceptProposal::class)->accept($project->id, $selected->id, true), [$other?->freelancer_id ?? $selected->freelancer_id]];
        }
        if ($event === NotificationEvent::VersionSubmitted) {
            $assignment = ProjectAssignment::factory()->create();
            $this->actingAs($assignment->freelancer);
            $token = (string) Str::uuid();

            return [fn () => app(SubmitDeliverable::class)->submit($assignment->project_id, null, ['title' => 'Private deliverable', 'submission_note' => 'Private note'], UploadedFile::fake()->createWithContent('work.txt', 'Private original work.'), $token), [$assignment->project->client_id]];
        }
        if (in_array($event, [NotificationEvent::VersionApproved, NotificationEvent::VersionChangesRequested], true)) {
            $version = DeliverableVersion::factory()->create();
            $assignment = $version->deliverable->assignment;
            $this->actingAs($assignment->project->client);

            return [fn () => $event === NotificationEvent::VersionApproved
                ? app(ReviewDeliverableVersion::class)->approve($assignment->project_id, $version->deliverable_id, $version->id, ['confirmed' => true])
                : app(ReviewDeliverableVersion::class)->requestChanges($assignment->project_id, $version->deliverable_id, $version->id, ['remarks' => 'Private changes requested']), [$assignment->freelancer_id]];
        }
        if ($event === NotificationEvent::MilestoneCompleted) {
            $milestone = Milestone::factory()->create();
            $assignment = $milestone->assignment;
            $this->actingAs($assignment->project->client);

            return [fn () => app(CompleteMilestone::class)->complete($assignment->project_id, $milestone->id, ['confirmed' => true, 'completion_note' => 'Accepted the outcome']), [$assignment->freelancer_id]];
        }
        if ($event === NotificationEvent::ProjectCompleted) {
            $review = DeliverableReview::factory()->create();
            $assignment = $review->version->deliverable->assignment;
            $this->actingAs($assignment->project->client);

            return [fn () => app(CompleteProject::class)->complete($assignment->project_id, ['confirmed' => true]), [$assignment->freelancer_id]];
        }
        if ($event === NotificationEvent::PaymentReported) {
            $assignment = ProjectAssignment::factory()->create();
            $this->actingAs($assignment->project->client);
            $token = (string) Str::uuid();

            return [fn () => app(ReportPayment::class)->report($assignment->project_id, ['confirmed' => true, 'method' => 'cash', 'paid_on' => CarbonImmutable::today('Asia/Colombo')->toDateString(), 'reference' => 'Private bank reference'], $token), [$assignment->freelancer_id]];
        }
        if ($event === NotificationEvent::ReceiptAcknowledged) {
            $report = PaymentReport::factory()->create();
            $this->actingAs($report->assignment->freelancer);

            return [fn () => app(AcknowledgePayment::class)->acknowledge($report->assignment->project_id, $report->id, ['confirmed' => true]), [$report->assignment->project->client_id]];
        }
        if ($event === NotificationEvent::DisputeOpened) {
            $assignment = ProjectAssignment::factory()->create();
            $this->actingAs($assignment->project->client);
            $token = (string) Str::uuid();

            return [fn () => app(OpenDispute::class)->open($assignment->project_id, ['title' => 'Private case title', 'reason' => 'Private original dispute reason', 'confirmed' => true], $token), [$assignment->freelancer_id]];
        }
        if ($event === NotificationEvent::DisputeStatementAdded) {
            $case = DisputeCase::factory()->create();
            $this->actingAs($case->opener);
            $token = (string) Str::uuid();

            return [fn () => app(SubmitDisputeStatement::class)->submit($case->id, ['body' => 'Private case statement'], $token), [$case->assignment->freelancer_id]];
        }
        if (in_array($event, [NotificationEvent::DisputeResolved, NotificationEvent::DisputeDismissed], true)) {
            $case = DisputeCase::factory()->create();
            $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

            return [fn () => app(ResolveDispute::class)->resolve($case->id, ['decision' => $event === NotificationEvent::DisputeResolved ? 'resolved' : 'dismissed', 'rationale' => 'Private resolution reasoning', 'confirmed' => true]), [$case->assignment->project->client_id, $case->assignment->freelancer_id]];
        }
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $token = (string) Str::uuid();

        return [fn () => app(SendProjectMessage::class)->send($assignment->project_id, ['body' => 'Private project message'], $token), [$assignment->freelancer_id]];
    }
}
