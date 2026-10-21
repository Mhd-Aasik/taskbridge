<?php

namespace Tests\Feature;

use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Actions\Messages\SendProjectMessage;
use App\Actions\Milestones\CompleteMilestone;
use App\Actions\Milestones\SaveMilestone;
use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Actions\Projects\CompleteProject;
use App\Actions\Projects\PublishProject;
use App\Actions\Projects\SaveProjectDraft;
use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Enums\ProjectStatus;
use App\Models\Category;
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
use Tests\Concerns\ManagesCategories;
use Tests\TestCase;

class AdminCategoryWorkflowTest extends TestCase
{
    use ManagesCategories, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));
        Storage::fake('deliverables');
    }

    public function test_deactivation_removes_listing_and_direct_discovery_and_reactivation_preserves_original_publication(): void
    {
        $admin = $this->categoryAdmin();
        $category = Category::factory()->create();
        $project = Project::factory()->create(['category_id' => $category->id, 'status' => 'open', 'published_at' => now()->subDay(), 'deadline' => '2026-12-10'])->fresh();
        $future = Project::factory()->create(['category_id' => $category->id, 'status' => 'open', 'published_at' => now()->addDay(), 'deadline' => '2026-12-10'])->fresh();
        $before = $project->getAttributes();
        $futureBefore = $future->getAttributes();
        $freelancer = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $this->actingAs($freelancer)->get(route('freelancer.projects.show', $project))->assertOk();
        $this->actingAs($admin);
        $this->categoryActivation($category, false);
        $this->actingAs($freelancer)->get(route('freelancer.projects.index'))->assertOk()->assertDontSee($project->title);
        $this->get(route('freelancer.projects.show', $project))->assertNotFound();
        $this->actingAs($project->client)->get(route('client.projects.show', $project))->assertOk()->assertSeeText("This project's category is inactive.", false)->assertSeeText('applications and Freelancer discovery are unavailable');
        $this->get(route('client.projects.index'))->assertOk()->assertSeeText("This project's category is inactive.", false);
        $this->actingAs($admin);
        $this->categoryActivation($category, true);
        $this->actingAs($freelancer)->get(route('freelancer.projects.show', $project))->assertOk();
        $this->get(route('freelancer.projects.show', $future))->assertNotFound();
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertSame($futureBefore, $future->fresh()->getAttributes());
    }

    public function test_inactive_categories_block_new_drafts_publication_submissions_and_acceptance_without_domain_changes(): void
    {
        $admin = $this->categoryAdmin();
        $category = Category::factory()->create();
        $draft = Project::factory()->create(['category_id' => $category->id, 'budget' => '5000.00', 'deadline' => '2026-12-10'])->fresh();
        $open = Project::factory()->create(['client_id' => $draft->client_id, 'category_id' => $category->id, 'status' => 'open', 'published_at' => now()->subDay(), 'deadline' => '2026-12-10'])->fresh();
        $proposal = Proposal::factory()->create(['project_id' => $open->id, 'proposed_deadline' => '2026-12-05'])->fresh();
        $draftBefore = $draft->getAttributes();
        $openBefore = $open->getAttributes();
        $offerBefore = $proposal->getAttributes();
        $this->categoryActivation($category, false);
        $this->actingAs($draft->client);
        $this->get(route('client.projects.edit', $draft))->assertOk()->assertSeeText('inactive');
        $operations = [fn () => app(SaveProjectDraft::class)->save(null, ['title' => 'New draft', 'description' => 'Valid project brief', 'category_id' => $category->id, 'budget' => '100.00', 'deadline' => '2026-12-10']),
            fn () => app(PublishProject::class)->publish($draft->client, $draft->id, true), fn () => app(AcceptProposal::class)->accept($open->id, $proposal->id, true)];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('An inactive category must block new work entry.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('project_assignments', 0);
            }
        }
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]));
        try {
            app(SubmitProposal::class)->submit($open->id, ['cover_letter' => 'Valid project proposal', 'proposed_budget' => '100.00', 'proposed_deadline' => '2026-12-05']);
            $this->fail('Proposal submission must recheck category activation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('proposals', 1);
        }
        $this->assertSame($draftBefore, $draft->fresh()->getAttributes());
        $this->assertSame($openBefore, $open->fresh()->getAttributes());
        $this->assertSame($offerBefore, $proposal->fresh()->getAttributes());
        $this->assertDatabaseCount('projects', 2);
        $this->actingAs($admin);
        $this->categoryActivation($category, true);
        $this->actingAs($draft->client);
        $this->assertTrue(app(PublishProject::class)->publish($draft->client, $draft->id, true));
    }

    public function test_category_deactivation_does_not_block_assigned_planning_uploads_reviews_completion_payments_messages_or_history(): void
    {
        $admin = $this->categoryAdmin();
        $project = Project::factory()->create(['deadline' => '2026-12-10']);
        $offer = Proposal::factory()->create(['project_id' => $project->id, 'proposed_deadline' => '2026-12-05']);
        $assignment = ProjectAssignment::factory()->create(['proposal_id' => $offer->id])->fresh();
        $originalAssignment = $assignment->getAttributes();
        $originalOffer = $offer->fresh()->getAttributes();
        $this->categoryActivation($project->category, false);
        $this->actingAs($project->client);
        $milestone = app(SaveMilestone::class)->save($project->id, null, ['title' => 'Deliver the agreed work', 'due_date' => '2026-12-03']);
        $task = app(SaveProjectTask::class)->save($project->id, null, ['title' => 'Prepare work', 'due_date' => '2026-12-02', 'milestone_id' => $milestone->id]);
        $task = app(SaveProjectTask::class)->save($project->id, $task->id, ['title' => 'Prepare final work', 'due_date' => '2026-12-02', 'milestone_id' => $milestone->id], $task->details_version);
        $this->actingAs($assignment->freelancer);
        app(ChangeProjectTaskStatus::class)->change($project->id, $task->id, 'done', $task->status_version);
        $version = app(SubmitDeliverable::class)->submit($project->id, null, ['title' => 'Agreed delivery', 'milestone_id' => $milestone->id], UploadedFile::fake()->createWithContent('delivery.txt', 'The final project work.'), (string) Str::uuid());
        $this->actingAs($project->client);
        app(ReviewDeliverableVersion::class)->approve($project->id, $version->deliverable_id, $version->id, ['confirmed' => true]);
        app(CompleteMilestone::class)->complete($project->id, $milestone->id, ['confirmed' => true]);
        app(CompleteProject::class)->complete($project->id, ['confirmed' => true]);
        $report = app(ReportPayment::class)->report($project->id, ['confirmed' => true, 'method' => 'cash', 'paid_on' => '2026-11-01'], (string) Str::uuid());
        app(SendProjectMessage::class)->send($project->id, ['body' => 'The final work has been reviewed.'], (string) Str::uuid());
        $this->actingAs($assignment->freelancer);
        app(AcknowledgePayment::class)->acknowledge($project->id, $report->id, ['confirmed' => true]);
        app(SendProjectMessage::class)->send($project->id, ['body' => 'Receipt acknowledged.'], (string) Str::uuid());
        foreach ([route('freelancer.assignments.show', $assignment), route('freelancer.proposals.show', $offer), route('projects.plan', $project), route('projects.completion', $project), route('projects.payment', $project), route('projects.messages', $project), route('projects.deliverables.show', [$project, $version->deliverable_id])] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('projects.deliverables.download', [$project, $version->deliverable_id, $version]))->assertOk();
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
        $this->assertFalse($project->category->fresh()->is_active);
        $this->assertSame($originalAssignment, $assignment->fresh()->getAttributes());
        $this->assertSame($originalOffer, $offer->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 1);
        $this->assertDatabaseCount('project_messages', 2);
    }
}
