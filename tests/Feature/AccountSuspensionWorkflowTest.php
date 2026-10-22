<?php

namespace Tests\Feature;

use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Actions\Disputes\OpenDispute;
use App\Actions\Messages\SendProjectMessage;
use App\Actions\Milestones\CompleteMilestone;
use App\Actions\Milestones\SaveMilestone;
use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Actions\Projects\CompleteProject;
use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ManagesAccounts;
use Tests\TestCase;

class AccountSuspensionWorkflowTest extends TestCase
{
    use ManagesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'));
        Storage::fake('deliverables');
        Storage::fake('project_covers');
    }

    public function test_client_suspension_hides_listing_and_direct_discovery_and_reactivation_preserves_publication(): void
    {
        $admin = $this->accountAdmin();
        $project = Project::factory()->create(['status' => 'open', 'published_at' => now()->subDay(), 'deadline' => '2026-12-10'])->fresh();
        $before = $project->getAttributes();
        $freelancer = User::factory()->create(['role' => 'freelancer']);
        $this->actingAs($freelancer)->get(route('freelancer.projects.show', $project))->assertOk();
        $this->actingAs($admin);
        $this->changeAccount($project->client, true);
        $this->actingAs($freelancer)->get(route('freelancer.projects.index'))->assertOk()->assertDontSee($project->title);
        $this->get(route('freelancer.projects.show', $project))->assertNotFound();
        try {
            app(SubmitProposal::class)->submit($project->id, ['cover_letter' => 'A meaningful offer', 'proposed_budget' => '200.00', 'proposed_deadline' => '2026-12-01']);
            $this->fail('A suspended owner cannot receive new proposals.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('project', $e->errors());
        }
        $this->actingAs($admin);
        $this->changeAccount($project->client, false);
        $this->actingAs($freelancer)->get(route('freelancer.projects.show', $project))->assertOk();
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_an_active_client_cannot_accept_a_suspended_freelancer_and_the_original_offer_is_preserved(): void
    {
        $admin = $this->accountAdmin();
        $project = Project::factory()->create(['status' => 'open', 'published_at' => now()->subDay(), 'deadline' => '2026-12-10'])->fresh();
        $offer = Proposal::factory()->create(['project_id' => $project->id, 'proposed_deadline' => '2026-12-01'])->fresh();
        $before = $offer->getAttributes();
        $projectBefore = $project->getAttributes();
        $this->changeAccount($offer->freelancer, true);
        $this->actingAs($project->client);
        $this->get(route('client.projects.proposals.show', [$project, $offer]))->assertOk()->assertSeeText($offer->freelancer->name);
        try {
            app(AcceptProposal::class)->accept($project->id, $offer->id, true);
            $this->fail('A suspended candidate must not be assigned.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proposal', $e->errors());
        }
        $this->assertSame($before, $offer->fresh()->getAttributes());
        $this->assertSame($projectBefore, $project->fresh()->getAttributes());
        $this->assertDatabaseCount('project_assignments', 0);
    }

    public function test_active_freelancer_keeps_work_download_payment_messaging_and_dispute_access_when_client_is_suspended(): void
    {
        $admin = $this->accountAdmin();
        $assignment = ProjectAssignment::factory()->create(['agreed_deadline' => '2026-12-05'])->fresh();
        $project = $assignment->project;
        $client = $project->client;
        $freelancer = $assignment->freelancer;
        $assignmentBefore = $assignment->getAttributes();
        $offerBefore = $assignment->proposal->fresh()->getAttributes();
        $this->actingAs($client);
        $task = app(SaveProjectTask::class)->save($project->id, null, ['title' => 'Prepare the delivery', 'due_date' => '2026-12-01']);
        $report = app(ReportPayment::class)->report($project->id, ['confirmed' => true, 'method' => 'cash', 'paid_on' => '2026-11-03'], (string) Str::uuid());
        $this->actingAs($admin);
        $this->changeAccount($client, true);
        $this->actingAs($freelancer);
        app(ChangeProjectTaskStatus::class)->change($project->id, $task->id, 'done', $task->status_version);
        $version = app(SubmitDeliverable::class)->submit($project->id, null, ['title' => 'Work for the client'], UploadedFile::fake()->createWithContent('work.txt', 'Existing private work.'), (string) Str::uuid());
        app(AcknowledgePayment::class)->acknowledge($project->id, $report->id, ['confirmed' => true]);
        app(SendProjectMessage::class)->send($project->id, ['body' => 'Work remains available.'], (string) Str::uuid());
        $case = app(OpenDispute::class)->open($project->id, ['title' => 'Need clarification', 'reason' => 'The assigned work needs clarification.', 'confirmed' => true], (string) Str::uuid());
        foreach ([route('freelancer.assignments.show', $assignment), route('freelancer.proposals.show', $assignment->proposal), route('projects.plan', $project), route('projects.payment', $project), route('projects.messages', $project), route('disputes.show', $case)] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('projects.deliverables.download', [$project, $version->deliverable_id, $version]))->assertOk();
        $this->assertSame($assignmentBefore, $assignment->fresh()->getAttributes());
        $this->assertSame($offerBefore, $assignment->proposal->fresh()->getAttributes());
        $this->assertSame('done', $task->fresh()->status->value);
        $this->assertFalse($client->fresh()->isActive());
        $this->assertDatabaseCount('project_messages', 1);
    }

    public function test_active_client_keeps_planning_review_payment_messages_and_history_when_freelancer_is_suspended(): void
    {
        $admin = $this->accountAdmin();
        $assignment = ProjectAssignment::factory()->create(['agreed_deadline' => '2026-12-05']);
        $project = $assignment->project;
        $this->actingAs($assignment->freelancer);
        $version = app(SubmitDeliverable::class)->submit($project->id, null, ['title' => 'Previously submitted work'], UploadedFile::fake()->createWithContent('work.txt', 'A private submitted file.'), (string) Str::uuid());
        $this->actingAs($admin);
        $this->changeAccount($assignment->freelancer, true);
        $this->actingAs($project->client);
        $milestone = app(SaveMilestone::class)->save($project->id, null, ['title' => 'Continue planning', 'due_date' => '2026-12-01']);
        app(ReviewDeliverableVersion::class)->approve($project->id, $version->deliverable_id, $version->id, ['confirmed' => true]);
        app(CompleteMilestone::class)->complete($project->id, $milestone->id, ['confirmed' => true, 'completion_note' => 'Planning outcome recorded without separate work items.']);
        app(CompleteProject::class)->complete($project->id, ['confirmed' => true]);
        app(ReportPayment::class)->report($project->id, ['confirmed' => true, 'method' => 'cash', 'paid_on' => '2026-11-03'], (string) Str::uuid());
        app(SendProjectMessage::class)->send($project->id, ['body' => 'Existing project history is preserved.'], (string) Str::uuid());
        foreach ([route('client.projects.show', $project), route('projects.plan', $project), route('projects.payment', $project), route('projects.messages', $project), route('projects.deliverables.show', [$project, $version->deliverable_id])] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertTrue($version->fresh()->review()->exists());
        Storage::disk('deliverables')->assertExists($version->storage_path);
        $this->assertDatabaseCount('admin_action_logs', 1);
    }

    public function test_suspended_historical_admins_do_not_receive_new_dispute_notifications(): void
    {
        $activeAdmin = User::factory()->create(['role' => 'admin']);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'suspended_at' => now()]);
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        app(OpenDispute::class)->open($assignment->project_id, ['title' => 'Review this case', 'reason' => 'The assigned project needs administrative review.', 'confirmed' => true], (string) Str::uuid());
        $this->assertSame(1, $activeAdmin->notifications()->count());
        $this->assertSame(0, $inactiveAdmin->notifications()->count());
        $this->assertModelExists($inactiveAdmin);
    }
}
