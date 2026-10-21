<?php

namespace Tests\Feature;

use App\Actions\Projects\CompleteProject;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskStatus;
use App\Models\Deliverable;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompletionPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 18:45:00', 'UTC'));
        Storage::fake('deliverables');
    }

    public function test_guests_are_redirected_to_login_and_reads_do_not_complete(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->get(route('projects.completion', $assignment->project_id))->assertRedirect(route('login'));
        $this->actingAs($assignment->project->client)->get(route('projects.completion', $assignment->project_id))->assertOk()
            ->assertSeeText('Submit at least one deliverable')->assertSee(route('projects.plan', $assignment->project_id), false)
            ->assertSee(route('projects.deliverables.index', $assignment->project_id), false);
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
        $this->assertNull($assignment->project->fresh()->completed_at);
    }

    #[DataProvider('unrelatedRoles')]
    public function test_unrelated_accounts_and_wrong_roles_cannot_read_or_mutate(string $role): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route('projects.completion', $assignment->project_id))->assertForbidden();
        Volt::test('projects.completion', ['project' => $assignment->project])->assertForbidden();
        try {
            app(CompleteProject::class)->complete($assignment->project_id, ['confirmed' => true]);
            $this->fail('An unrelated actor must be refused.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $exception->status() ?? 403);
        }
        $this->assertNull($milestone->fresh()->completed_at);
    }

    public function test_assigned_freelancer_has_read_only_access_and_cannot_invoke_either_completion_action(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->freelancer);
        $this->get(route('projects.completion', $assignment->project_id))->assertOk()->assertDontSeeText('Confirm project completion');
        Volt::test('projects.completion', ['project' => $assignment->project])->set('confirmed', true)->call('complete')->assertForbidden();
        Volt::test('projects.plan', ['project' => $assignment->project])->set('milestoneNotes', [$milestone->id => 'Outcome'])->set('milestoneConfirmed', [$milestone->id => true])->call('completeMilestone', $milestone->id)->assertForbidden();
        $this->assertNull($milestone->fresh()->completed_at);
        $this->assertNull($assignment->project->fresh()->completed_at);
    }

    public function test_owner_completes_empty_milestone_with_accessible_confirmation_validation_and_retained_note(): void
    {
        $milestone = Milestone::factory()->create();
        $assignment = $milestone->assignment;
        $this->actingAs($assignment->project->client);
        $this->get(route('projects.plan', $assignment->project_id))->assertOk()->assertSeeText('Complete milestone')->assertSeeText('Completion locks this milestone');
        $form = Volt::test('projects.plan', ['project' => $assignment->project])->set('milestoneConfirmed', [$milestone->id => true])
            ->set('milestoneNotes', [$milestone->id => '   '])->call('completeMilestone', $milestone->id)
            ->assertHasErrors(['milestoneNotes.'.$milestone->id])->assertSet('milestoneNotes.'.$milestone->id, '   ');
        $form->set('milestoneNotes', [$milestone->id => "  Outcome\n<script>alert(1)</script>  "])->call('completeMilestone', $milestone->id)->assertHasNoErrors()
            ->assertSee('Milestone completed.')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSeeText('26 October 2026, 12:15 am');
        $this->assertTrue($milestone->fresh()->isCompleted());
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
    }

    public function test_project_confirmation_rechecks_pending_work_and_preserves_input_then_records_completion(): void
    {
        [$assignment] = $this->ready();
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.completion', ['project' => $assignment->project])->assertViewHas('readiness', fn ($r) => $r['ready']);
        // Simulated interleaving: a task is added after the Client viewed readiness.
        $task = ProjectTask::factory()->create(['project_assignment_id' => $assignment->id]);
        $form->set('confirmed', true)->set('completion_note', '  Outcome preserved.  ')->call('complete')
            ->assertHasErrors(['completion'])->assertSet('completion_note', '  Outcome preserved.  ');
        $task->forceFill(['status' => ProjectTaskStatus::Done])->save();
        $form->call('complete')->assertHasNoErrors()->assertSeeText('Project completed.')->assertDontSeeText('Confirm project completion');
        $this->assertSame('Outcome preserved.', $assignment->project->fresh()->completion_note);
        $this->assertSame(ProjectStatus::Completed, $assignment->project->fresh()->status);
    }

    public function test_filtered_tasks_do_not_distort_milestone_or_project_readiness(): void
    {
        $milestone = Milestone::factory()->create();
        ProjectTask::factory()->create(['milestone_id' => $milestone->id]);
        ProjectTask::factory()->create(['milestone_id' => $milestone->id, 'status' => ProjectTaskStatus::Done]);
        $this->actingAs($milestone->assignment->project->client);
        Volt::test('projects.plan', ['project' => $milestone->assignment->project])->set('taskStatusFilter', 'done')
            ->assertSeeText('1 unfinished task(s) must be marked done.')->assertViewHas('taskProgress', ['total' => 2, 'completed' => 1, 'percentage' => 50]);
        Volt::test('projects.completion', ['project' => $milestone->assignment->project])->assertViewHas('readiness', fn ($r) => $r['tasks'] === 2 && $r['completed_tasks'] === 1 && ! $r['ready']);
    }

    public function test_mismatched_milestone_ids_are_denied(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $foreign = Milestone::factory()->create();
        $this->actingAs($assignment->project->client);
        Volt::test('projects.plan', ['project' => $assignment->project])->set('milestoneConfirmed', [$foreign->id => true])->call('completeMilestone', $foreign->id)->assertNotFound();
        $this->assertNull($foreign->fresh()->completed_at);
    }

    public function test_persisted_role_change_revokes_a_stale_completion_form(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.completion', ['project' => $assignment->project])->set('confirmed', true);
        DB::table('users')->where('id', $assignment->project->client_id)->update(['role' => User::ROLE_ADMIN]);
        $form->call('complete')->assertForbidden();
        $this->assertNull($assignment->project->fresh()->completed_at);
    }

    public function test_both_parties_see_escaped_completion_notes_times_links_dashboard_counts_and_private_history(): void
    {
        [$assignment, $version] = $this->ready();
        $this->actingAs($assignment->project->client);
        app(CompleteProject::class)->complete($assignment->project_id, ['confirmed' => true, 'completion_note' => "Outcome\n<img src=x onerror=alert(1)>"]);
        $milestone = $assignment->milestones->sole();
        foreach ([$assignment->project->client, $assignment->freelancer] as $actor) {
            $this->actingAs($actor);
            $this->get(route('projects.completion', $assignment->project_id))->assertOk()->assertSeeText('26 October 2026, 12:15 am')
                ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('<img src=x onerror=alert(1)>', false)->assertDontSeeText('Confirm project completion');
            $this->get(route('projects.plan', $assignment->project_id))->assertOk()->assertSeeText('Completed by')->assertDontSeeText('Edit milestone')->assertDontSeeText('Update task status');
            $this->get(route('projects.deliverables.download', [$assignment->project_id, $version->deliverable_id, $version->id]))->assertOk()->assertStreamedContent('Private completed history.');
        }
        $this->actingAs($assignment->project->client)->get(route('client.projects.show', $assignment->project_id))->assertOk()->assertSeeText('Completed by')->assertSee(route('projects.completion', $assignment->project_id), false);
        $this->get(route('client.dashboard'))->assertOk()->assertViewHas('completedProjectCount', 1)->assertSeeText('Completed by');
        $this->actingAs($assignment->freelancer)->get(route('freelancer.assignments.show', $assignment))->assertOk()->assertSeeText('Completed by');
        $this->get(route('freelancer.dashboard'))->assertOk()->assertViewHas('completedAssignmentCount', 1)->assertSeeText('Completed by');
        $this->assertTrue($milestone->fresh()->isCompleted());
    }

    public function test_unassigned_projects_cannot_be_completed(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client)->get(route('projects.completion', $project))->assertNotFound();
        $this->assertNull($project->fresh()->completed_at);
    }

    private function ready(): array
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->completed()->create(['project_assignment_id' => $assignment->id]);
        $deliverable = Deliverable::factory()->create(['project_assignment_id' => $assignment->id, 'milestone_id' => $milestone->id]);
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id, 'original_filename' => 'history.txt', 'mime_type' => 'text/plain']);
        DeliverableReview::factory()->create(['deliverable_version_id' => $version->id]);
        Storage::disk('deliverables')->put($version->storage_path, 'Private completed history.');

        return [$assignment, $version];
    }

    public static function unrelatedRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
