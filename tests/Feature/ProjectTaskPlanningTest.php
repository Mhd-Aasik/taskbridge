<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskStatus;
use App\Models\FreelancerProfile;
use App\Models\Milestone;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectTaskPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));
    }

    public function test_client_creates_a_task_with_scoped_membership_and_server_metadata(): void
    {
        $assignment = $this->assignment();
        $milestone = $this->milestone($assignment);
        $before = $assignment->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        $this->form($assignment, ['title' => '  Prepare the first draft  ', 'description' => "  First line\nSecond line  ", 'milestone_id' => (string) $milestone->id])
            ->call('saveTask')->assertHasNoErrors()->assertSeeText('Task added to the project plan.')
            ->assertSet('taskForm.title', '')->assertSet('editingTaskId', null)->assertSet('taskDetailsVersion', null);

        $task = ProjectTask::sole();
        $this->assertSame('Prepare the first draft', $task->title);
        $this->assertSame("First line\nSecond line", $task->description);
        $this->assertSame($assignment->id, $task->project_assignment_id);
        $this->assertSame($milestone->id, $task->milestone_id);
        $this->assertSame($assignment->project->client_id, $task->created_by);
        $this->assertSame(ProjectTaskStatus::Todo, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertSame($before, $assignment->fresh()->getAttributes());
    }

    public function test_client_loads_saved_values_edits_membership_and_cancels_without_writing(): void
    {
        $assignment = $this->assignment();
        $milestone = $this->milestone($assignment);
        $task = $this->task($assignment, ['title' => 'Saved task', 'description' => "Saved\nlines", 'milestone_id' => $milestone->id]);
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id)
            ->assertHasNoErrors()->assertDispatched('task-editing')->assertSet('editingTaskId', $task->id)
            ->assertSet('taskDetailsVersion', 1)->assertSet('taskForm.title', 'Saved task')
            ->assertSet('taskForm.description', "Saved\nlines")->assertSet('taskForm.due_date', '2026-10-24')
            ->assertSet('taskForm.milestone_id', (string) $milestone->id);
        $form->set('taskForm.title', '  Revised task  ')->set('taskForm.description', '')->set('taskForm.milestone_id', '')
            ->call('saveTask')->assertHasNoErrors()->assertSeeText('Task changes saved.')->assertSet('editingTaskId', null);
        $task->refresh();
        $this->assertSame('Revised task', $task->title);
        $this->assertNull($task->description);
        $this->assertNull($task->milestone_id);
        $this->assertSame(2, $task->details_version);
        $form->call('editTask', $task->id)->set('taskForm.title', 'Unsaved text')->call('cancelTaskEdit')
            ->assertHasNoErrors()->assertSet('taskForm.title', '')->assertSet('editingTaskId', null)->assertSet('taskDetailsVersion', null);
        $this->assertSame('Revised task', $task->fresh()->title);
    }

    public function test_validation_keeps_input_and_saved_task_unchanged(): void
    {
        $assignment = $this->assignment();
        $milestone = $this->milestone($assignment);
        $task = $this->task($assignment);
        $before = $task->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id)
            ->set('taskForm.title', 'Retained title')->set('taskForm.description', "Retained\ninput")
            ->set('taskForm.milestone_id', (string) $milestone->id)->set('taskForm.due_date', '2026-10-26')
            ->call('saveTask')->assertHasErrors(['taskForm.due_date'])->assertSet('editingTaskId', $task->id)
            ->assertSet('taskForm.title', 'Retained title')->assertSet('taskForm.description', "Retained\ninput")
            ->assertSet('taskForm.milestone_id', (string) $milestone->id)->assertSet('taskForm.due_date', '2026-10-26');
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertDatabaseCount('project_tasks', 1);
    }

    public function test_freelancer_reads_tasks_and_changes_status_but_has_no_detail_editor(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $this->actingAs($assignment->freelancer);
        $this->get($this->url($assignment))->assertOk()->assertSeeText($task->title)->assertSeeText('Update task status')
            ->assertDontSee('wire:submit="saveTask"', false)->assertDontSeeText('Edit task')->assertDontSeeText('Add a task');
        Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id)->assertForbidden();
        $this->form($assignment)->call('saveTask')->assertForbidden();
        Volt::test('projects.plan', ['project' => $assignment->project])->call('changeTaskStatus', $task->id, 'in_progress', 1)
            ->assertHasNoErrors()->assertSeeText('Task status saved.');
        $this->assertSame(ProjectTaskStatus::InProgress, $task->fresh()->status);
    }

    #[DataProvider('participants')]
    public function test_both_parties_can_complete_retry_and_reopen_tasks(string $participant): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $this->actingAs($participant === 'client' ? $assignment->project->client : $assignment->freelancer);
        $form = Volt::test('projects.plan', ['project' => $assignment->project]);
        $form->call('changeTaskStatus', $task->id, 'done', 1)->assertHasNoErrors()->assertSeeText('100% task completion');
        $completed = $task->fresh()->getAttributes();
        $this->assertSame('2026-10-23 12:00:00', $task->fresh()->completed_at->utc()->format('Y-m-d H:i:s'));
        $this->travel(1)->hours();
        $form->call('changeTaskStatus', $task->id, 'done', 1)->assertHasNoErrors();
        $this->assertSame($completed, $task->fresh()->getAttributes());
        $form->call('changeTaskStatus', $task->id, 'todo', 2)->assertHasNoErrors()->assertSeeText('0% task completion');
        $this->assertSame(ProjectTaskStatus::Todo, $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
    }

    public function test_detail_saving_preserves_a_status_change_made_after_the_editor_loaded(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id);
        Volt::test('projects.plan', ['project' => $assignment->project])->call('changeTaskStatus', $task->id, 'done', 1)->assertHasNoErrors();
        $completedAt = $task->fresh()->completed_at;
        $form->set('taskForm.title', 'Revised details')->call('saveTask')->assertHasNoErrors();
        $task->refresh();
        $this->assertSame('Revised details', $task->title);
        $this->assertSame(ProjectTaskStatus::Done, $task->status);
        $this->assertTrue($completedAt->equalTo($task->completed_at));
        $this->assertSame(2, $task->details_version);
        $this->assertSame(2, $task->status_version);
    }

    public function test_same_second_stale_detail_saves_are_refused_with_entered_values_retained(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $this->actingAs($assignment->project->client);
        $first = Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id);
        $stale = Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id);
        $first->set('taskForm.title', 'First saved change')->call('saveTask')->assertHasNoErrors();
        $before = $task->fresh()->getAttributes();
        $stale->set('taskForm.title', 'My retained stale title')->set('taskForm.description', 'My retained details')
            ->call('saveTask')->assertHasErrors(['task_conflict'])->assertSeeText('Reload the task before saving')
            ->assertSet('taskForm.title', 'My retained stale title')->assertSet('taskForm.description', 'My retained details');
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_stale_conflicting_status_is_refused_and_the_current_saved_state_is_rendered(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $this->actingAs($assignment->freelancer);
        $stale = Volt::test('projects.plan', ['project' => $assignment->project]);
        Volt::test('projects.plan', ['project' => $assignment->project])->call('changeTaskStatus', $task->id, 'in_progress', 1)->assertHasNoErrors();
        $before = $task->fresh()->getAttributes();
        $stale->call('changeTaskStatus', $task->id, 'done', 1)->assertHasErrors(['task_conflict'])->assertSeeText('Review the current status');
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_guest_and_unrelated_accounts_cannot_read_or_mutate_tasks(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $before = $task->fresh()->getAttributes();
        $this->get($this->url($assignment))->assertRedirect(route('login'));
        Volt::test('projects.plan', ['project' => $assignment->project])->assertForbidden();
        foreach ([User::ROLE_CLIENT, User::ROLE_FREELANCER, User::ROLE_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get($this->url($assignment))->assertForbidden()->assertDontSeeText($task->title);
            Volt::test('projects.plan', ['project' => $assignment->project])->assertForbidden();
        }
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_current_participant_roles_are_rechecked_on_livewire_requests(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $owner = $assignment->project->client;
        $this->actingAs($owner);
        $form = $this->form($assignment);
        DB::table('users')->where('id', $owner->id)->update(['role' => User::ROLE_ADMIN]);
        $form->call('saveTask')->assertForbidden();
        $this->actingAs($assignment->freelancer);
        $form = Volt::test('projects.plan', ['project' => $assignment->project]);
        DB::table('users')->where('id', $assignment->freelancer_id)->update(['role' => User::ROLE_CLIENT]);
        $form->call('changeTaskStatus', $task->id, 'done', 1)->assertForbidden();
        $this->assertSame(ProjectTaskStatus::Todo, $task->fresh()->status);
    }

    public function test_other_assignment_tasks_and_milestones_are_not_resolved_from_the_page(): void
    {
        $assignment = $this->assignment();
        $other = $this->assignment();
        $other->project->forceFill(['client_id' => $assignment->project->client_id])->save();
        $task = $this->task($other);
        $milestone = $this->milestone($other);
        $before = $task->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id)->assertNotFound();
        Volt::test('projects.plan', ['project' => $assignment->project])->call('changeTaskStatus', $task->id, 'done', 1)->assertNotFound();
        $this->form($assignment, ['milestone_id' => (string) $milestone->id])->call('saveTask')->assertHasErrors(['taskForm.milestone_id']);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(0, $assignment->tasks()->count());
    }

    #[DataProvider('protectedFormFields')]
    public function test_protected_fields_cannot_be_injected_into_the_task_form(string $field): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->form($assignment)->set('taskForm.'.$field, '1')->assertForbidden();
        $this->assertDatabaseCount('project_tasks', 0);
    }

    #[DataProvider('lockedTaskFields')]
    public function test_task_edit_identifiers_and_version_are_locked(string $field): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.plan', ['project' => $assignment->project]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $form->set($field, 999999);
    }

    #[DataProvider('historicalStatuses')]
    public function test_status_changes_make_tasks_read_only_but_preserve_both_parties_history(ProjectStatus $status): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $before = $task->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id)->set('taskForm.title', 'Retained after closure');
        DB::table('projects')->where('id', $assignment->project_id)->update(['status' => $status->value]);
        $form->call('saveTask')->assertHasErrors(['plan'])->assertSet('taskForm.title', 'Retained after closure');
        foreach ([$assignment->project->client, $assignment->freelancer] as $user) {
            $this->actingAs($user);
            $this->get($this->url($assignment))->assertOk()->assertSeeText($task->title)->assertSeeText('Task history is read-only')
                ->assertDontSee('wire:submit="saveTask"', false)->assertDontSeeText('Update task status')->assertDontSeeText('Edit task');
            Volt::test('projects.plan', ['project' => $assignment->project])->call('changeTaskStatus', $task->id, 'done', 1)->assertHasErrors(['plan']);
        }
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_empty_milestones_and_empty_plans_show_real_zero_progress_and_working_links(): void
    {
        $assignment = $this->assignment();
        $milestone = $this->milestone($assignment);
        foreach ([$assignment->project->client, $assignment->freelancer] as $user) {
            $this->actingAs($user);
            $this->get($this->url($assignment))->assertOk()->assertSeeText($milestone->title)->assertSeeText('No tasks yet.')
                ->assertSeeText('0 of 0 tasks done')->assertDontSeeText('0% task completion')->assertSeeText('not Client approval or project acceptance');
            Volt::test('projects.plan', ['project' => $assignment->project])->assertViewHas('taskProgress', $this->progress(0, 0))
                ->assertViewHas('ungroupedProgress', $this->progress(0, 0))
                ->assertViewHas('milestones', fn ($milestones) => $milestones->sole()->tasks_count === 0 && $milestones->sole()->completed_tasks_count === 0);
        }
        $this->actingAs($assignment->project->client)->get(route('client.projects.show', $assignment->project))->assertOk()->assertSee($this->url($assignment))->assertSeeText('No tasks yet.');
        $this->actingAs($assignment->freelancer)->get(route('freelancer.assignments.show', $assignment))->assertOk()->assertSee($this->url($assignment))->assertSeeText('No tasks yet.');
    }

    public function test_progress_is_scoped_and_uses_all_persisted_tasks_independently_of_filtering(): void
    {
        $assignment = $this->assignment();
        $milestone = $this->milestone($assignment);
        $done = $this->task($assignment, ['milestone_id' => $milestone->id, 'title' => 'Completed milestone task', 'status' => ProjectTaskStatus::Done]);
        $todo = $this->task($assignment, ['milestone_id' => $milestone->id, 'title' => 'Outstanding milestone task']);
        $ungroupedDone = $this->task($assignment, ['title' => 'Completed ungrouped task', 'status' => ProjectTaskStatus::Done]);
        $this->task($assignment, ['title' => 'Started ungrouped task', 'status' => ProjectTaskStatus::InProgress]);
        $other = $this->task($this->assignment(), ['title' => 'Another project secret', 'status' => ProjectTaskStatus::Done]);
        $this->actingAs($assignment->freelancer);
        $form = Volt::test('projects.plan', ['project' => $assignment->project])
            ->assertViewHas('taskProgress', $this->progress(4, 2))->assertViewHas('ungroupedProgress', $this->progress(2, 1))
            ->assertViewHas('milestones', fn ($milestones) => $milestones->sole()->tasks_count === 2 && $milestones->sole()->completed_tasks_count === 1)
            ->assertDontSeeText($other->title);
        $form->set('taskStatusFilter', 'done')->assertHasNoErrors()->assertSeeText($done->title)->assertSeeText($ungroupedDone->title)
            ->assertDontSeeText($todo->title)->assertSeeText('2 of 4 tasks done')->assertSeeText('50% task completion')
            ->assertViewHas('taskProgress', $this->progress(4, 2))->assertViewHas('ungroupedProgress', $this->progress(2, 1))
            ->assertViewHas('tasksByMilestone', fn ($tasks) => $tasks->get($milestone->id)->modelKeys() === [$done->id]);
        $form->set('taskStatusFilter', 'todo')->assertHasNoErrors()->assertSeeText($todo->title)->assertDontSeeText($done->title)
            ->assertSeeText('No tasks match this status filter.')->assertViewHas('taskProgress', $this->progress(4, 2));
        $form->set('taskStatusFilter', '')->assertHasNoErrors()->assertSeeText($done->title)->assertSeeText($todo->title);
    }

    public function test_partial_percentages_are_derived_from_counts_and_complete_progress_is_real(): void
    {
        $assignment = $this->assignment();
        $first = $this->task($assignment, ['status' => ProjectTaskStatus::Done]);
        $second = $this->task($assignment);
        $third = $this->task($assignment);
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.plan', ['project' => $assignment->project])->assertViewHas('taskProgress', $this->progress(3, 1))->assertSeeText('33% task completion');
        $form->call('changeTaskStatus', $second->id, 'done', 1)->call('changeTaskStatus', $third->id, 'done', 1)
            ->assertHasNoErrors()->assertViewHas('taskProgress', $this->progress(3, 3))->assertSeeText('100% task completion');
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
        $this->assertSame(ProjectTaskStatus::Done, $first->fresh()->status);
        $this->get(route('client.projects.show', $assignment->project))->assertOk()->assertSeeText('3 of 3 tasks done');
        $this->actingAs($assignment->freelancer)->get(route('freelancer.assignments.show', $assignment))->assertOk()->assertSeeText('3 of 3 tasks done');
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_status_filters_are_rejected_without_leaking_unfiltered_task_results(mixed $filter): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment, ['title' => 'Do not render with invalid filter']);
        $this->actingAs($assignment->freelancer);
        Volt::test('projects.plan', ['project' => $assignment->project])->set('taskStatusFilter', $filter)
            ->assertHasErrors(['taskStatusFilter'])->assertDontSeeText($task->title)
            ->assertViewHas('taskProgress', $this->progress(1, 0));
    }

    public function test_tasks_are_ordered_by_due_date_and_id_and_grouped_by_their_own_milestone(): void
    {
        $assignment = $this->assignment();
        $milestone = $this->milestone($assignment);
        $late = $this->task($assignment, ['milestone_id' => $milestone->id, 'title' => 'Last by deadline', 'due_date' => '2026-10-25']);
        $first = $this->task($assignment, ['milestone_id' => $milestone->id, 'title' => 'First by deadline', 'due_date' => '2026-10-22']);
        $second = $this->task($assignment, ['milestone_id' => $milestone->id, 'title' => 'Second by identity', 'due_date' => '2026-10-22']);
        $ungrouped = $this->task($assignment, ['title' => 'Ungrouped only', 'due_date' => '2026-10-21']);
        $this->actingAs($assignment->freelancer);
        Volt::test('projects.plan', ['project' => $assignment->project])
            ->assertViewHas('tasksByMilestone', fn ($tasks) => $tasks->get($milestone->id)->modelKeys() === [$first->id, $second->id, $late->id])
            ->assertViewHas('ungroupedTasks', fn ($tasks) => $tasks->modelKeys() === [$ungrouped->id]);
        $this->get($this->url($assignment))->assertOk()->assertSeeInOrder([$first->title, $second->title, $late->title, $ungrouped->title]);
    }

    public function test_task_content_is_escaped_multiline_and_private_information_is_excluded(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment, ['title' => '<script>Task title</script>', 'description' => "First line\n<img src=x onerror=alert(1)>"]);
        $profile = FreelancerProfile::factory()->create(['user_id' => $assignment->freelancer_id, 'bio' => 'Private biography not for planning']);
        $competitor = Proposal::factory()->create(['project_id' => $assignment->project_id, 'cover_letter' => 'Competing private proposal']);
        foreach ([$assignment->project->client, $assignment->freelancer] as $user) {
            $this->actingAs($user);
            $response = $this->get($this->url($assignment))->assertOk()->assertSee(e($task->title), false)->assertDontSee($task->title, false)
                ->assertSee(e($task->description), false)->assertDontSee($task->description, false)->assertSee('whitespace-pre-wrap', false)
                ->assertDontSeeText($profile->bio)->assertDontSeeText($competitor->cover_letter)
                ->assertDontSeeText('Delete task')->assertDontSeeText('Approve deliverable');
            $otherParty = $user->id === $assignment->freelancer_id ? $assignment->project->client : $assignment->freelancer;
            $response->assertDontSee($otherParty->email)->assertDontSee(route('account.photo', $otherParty));
        }
    }

    public function test_overdue_indicators_use_the_sri_lankan_day_and_exclude_completed_tasks(): void
    {
        $assignment = $this->assignment();
        $unfinished = $this->task($assignment, ['title' => 'Unfinished yesterday', 'due_date' => '2026-10-22']);
        $completed = $this->task($assignment, ['title' => 'Completed yesterday', 'due_date' => '2026-10-22', 'status' => ProjectTaskStatus::Done, 'completed_at' => '2026-10-22 12:00:00']);
        $this->actingAs($assignment->freelancer);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 18:29:59', 'UTC'));
        $beforeMidnight = $this->get($this->url($assignment))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/wire:key="project-task-'.$unfinished->id.'"[^>]*>.*?Overdue.*?<\/li>/s', $beforeMidnight);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 18:30:00', 'UTC'));
        $afterMidnight = $this->get($this->url($assignment))->assertOk()->getContent();
        preg_match('/wire:key="project-task-'.$unfinished->id.'"[^>]*>(.*?)<\/li>/s', $afterMidnight, $unfinishedCard);
        preg_match('/wire:key="project-task-'.$completed->id.'"[^>]*>(.*?)<\/li>/s', $afterMidnight, $completedCard);
        $this->assertStringContainsString('Overdue', $unfinishedCard[1]);
        $this->assertStringNotContainsString('Overdue', $completedCard[1]);
        $this->assertStringContainsString('22 October 2026, 5:30 pm', $completedCard[1]);
    }

    public function test_controls_provide_labels_loading_feedback_live_confirmation_and_existing_navigation(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $this->actingAs($assignment->project->client);
        $this->get($this->url($assignment))->assertOk()->assertSee('aria-label="Task details"', false)
            ->assertSee('aria-label="Change status for '.e($task->title).'"', false)->assertSee('id="task-status-'.$task->id.'"', false)
            ->assertSeeText('Task status for '.$task->title)->assertSee('data-flux-select-native', false)
            ->assertSee('wire:loading.attr="disabled"', false)->assertSee('aria-live="polite"', false)
            ->assertSeeText('Saving task...')->assertSeeText('Loading saved task...')->assertSeeText('Saving task status...')
            ->assertSee('aria-label="Choose theme"', false)->assertSee(route('settings.profile'))->assertSee(route('logout'));
    }

    public function test_get_and_unmodified_forms_do_not_mutate_tasks(): void
    {
        $assignment = $this->assignment();
        $task = $this->task($assignment);
        $before = $task->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        $this->get($this->url($assignment).'?saveTask=1&status=done&title=Forged')->assertOk();
        $this->post($this->url($assignment))->assertStatus(405);
        Volt::test('projects.plan', ['project' => $assignment->project])->call('editTask', $task->id)->call('saveTask')->assertHasNoErrors();
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertDatabaseCount('project_tasks', 1);
    }

    public function test_query_counts_do_not_grow_once_per_task_or_milestone(): void
    {
        $assignment = $this->assignment();
        $milestone = $this->milestone($assignment);
        $this->task($assignment, ['milestone_id' => $milestone->id]);
        $this->actingAs($assignment->freelancer);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select')) {
                $queries[] = $query->sql;
            }
        });
        $this->get($this->url($assignment))->assertOk();
        $single = count($queries);
        for ($i = 0; $i < 9; $i++) {
            $next = $this->milestone($assignment);
            $this->task($assignment, ['milestone_id' => $next->id]);
        }
        $queries = [];
        $this->get($this->url($assignment))->assertOk();
        $this->assertCount($single, $queries);
    }

    private function assignment(): ProjectAssignment
    {
        return ProjectAssignment::factory()->create(['assigned_at' => '2026-10-20 18:30:00', 'agreed_deadline' => '2026-10-25']);
    }

    private function milestone(ProjectAssignment $assignment): Milestone
    {
        return Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'due_date' => '2026-10-25']);
    }

    private function task(ProjectAssignment $assignment, array $changes = []): ProjectTask
    {
        return ProjectTask::factory()->create(array_replace(['project_assignment_id' => $assignment->id, 'due_date' => '2026-10-24'], $changes));
    }

    private function form(ProjectAssignment $assignment, array $changes = []): Testable
    {
        $form = Volt::test('projects.plan', ['project' => $assignment->project]);
        foreach (array_replace(['title' => 'Prepare first draft', 'description' => 'Use the agreed brief.', 'due_date' => '2026-10-24', 'milestone_id' => ''], $changes) as $field => $value) {
            $form->set('taskForm.'.$field, $value);
        }

        return $form;
    }

    private function url(ProjectAssignment $assignment): string
    {
        return route('projects.plan', $assignment->project_id);
    }

    private function progress(int $total, int $completed): array
    {
        return ['total' => $total, 'completed' => $completed, 'percentage' => $total === 0 ? 0 : intdiv($completed * 100, $total)];
    }

    public static function participants(): array
    {
        return [['client'], ['freelancer']];
    }

    public static function protectedFormFields(): array
    {
        return array_map(fn ($field) => [$field], ['id', 'project_assignment_id', 'created_by', 'status', 'completed_at', 'details_version', 'status_version']);
    }

    public static function lockedTaskFields(): array
    {
        return [['projectId'], ['editingTaskId'], ['taskDetailsVersion']];
    }

    public static function historicalStatuses(): array
    {
        return array_map(fn ($status) => [$status], array_filter(ProjectStatus::cases(), fn ($status) => $status !== ProjectStatus::InProgress));
    }

    public static function invalidFilters(): array
    {
        return [['unknown'], ["done' OR 1=1"], [['todo']], [12]];
    }
}
