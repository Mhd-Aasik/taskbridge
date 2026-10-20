<?php

namespace Tests\Feature;

use App\Actions\Milestones\SaveMilestone;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProjectTaskActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));
    }

    public function test_client_creates_trimmed_tasks_with_server_controlled_metadata(): void
    {
        $assignment = $this->assignment();
        $before = $assignment->fresh()->getAttributes();
        $projectBefore = $assignment->project->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        $task = app(SaveProjectTask::class)->save($assignment->project_id, null, $this->details([
            'title' => '  Review the brief  ', 'description' => "  First line.\nSecond line.  ",
            'id' => 999999, 'project_assignment_id' => 999999, 'created_by' => 999999,
            'status' => 'done', 'completed_at' => '2000-01-01', 'details_version' => 400, 'status_version' => 400,
            'role' => 'admin', 'agreed_budget' => '1.00', 'agreed_deadline' => '2099-01-01',
        ]));
        $task->refresh();
        $this->assertSame('Review the brief', $task->title);
        $this->assertSame("First line.\nSecond line.", $task->description);
        $this->assertSame($assignment->id, $task->project_assignment_id);
        $this->assertSame($assignment->project->client_id, $task->created_by);
        $this->assertNull($task->milestone_id);
        $this->assertSame(ProjectTaskStatus::Todo, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertSame(1, $task->details_version);
        $this->assertSame(1, $task->status_version);
        $this->assertSame($before, $assignment->fresh()->getAttributes());
        $this->assertSame($projectBefore, $assignment->project->fresh()->getAttributes());
        $this->assertSame(User::ROLE_CLIENT, $assignment->project->client->fresh()->role);
    }

    public function test_owner_edits_details_and_membership_without_overwriting_status_or_completion(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'due_date' => '2026-10-24']);
        $task = ProjectTask::factory()->create([
            'project_assignment_id' => $assignment->id, 'status' => ProjectTaskStatus::Done,
            'completed_at' => '2026-10-23 10:00:00',
        ])->fresh();
        $before = $task->getAttributes();
        $this->actingAs($assignment->project->client);
        $saved = app(SaveProjectTask::class)->save($assignment->project_id, $task->id, $this->details([
            'title' => 'Updated title', 'description' => '   ', 'milestone_id' => (string) $milestone->id,
            'project_assignment_id' => 999999, 'created_by' => 999999, 'status' => 'todo', 'completed_at' => null,
        ]), $task->details_version)->fresh();
        $this->assertSame('Updated title', $saved->title);
        $this->assertNull($saved->description);
        $this->assertSame($milestone->id, $saved->milestone_id);
        $this->assertSame($before['details_version'] + 1, $saved->details_version);
        foreach (['project_assignment_id', 'created_by', 'status', 'completed_at', 'status_version', 'created_at'] as $field) {
            $this->assertSame($before[$field], $saved->getRawOriginal($field));
        }
        $saved = app(SaveProjectTask::class)->save($assignment->project_id, $task->id, $this->details(['milestone_id' => null]), $saved->details_version);
        $this->assertNull($saved->fresh()->milestone_id);
    }

    #[DataProvider('guestActions')]
    public function test_guests_cannot_mutate_tasks(string $action): void
    {
        $task = $this->task();
        $this->expectException(HttpException::class);
        $this->perform($action, $task);
    }

    #[DataProvider('unrelatedActors')]
    public function test_unrelated_accounts_and_wrong_roles_cannot_mutate_tasks(string $role, string $action): void
    {
        $task = $this->task();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->expectException(AuthorizationException::class);
        $this->perform($action, $task);
    }

    public function test_assigned_freelancer_cannot_create_or_edit_task_details(): void
    {
        $task = $this->task();
        $this->actingAs($task->assignment->freelancer);
        foreach ([null, $task->id] as $id) {
            try {
                app(SaveProjectTask::class)->save($task->assignment->project_id, $id, $this->details(), $task->details_version);
                $this->fail('Expected Client-only task details authorization.');
            } catch (AuthorizationException) {
                $this->assertSame(1, ProjectTask::count());
            }
        }
    }

    #[DataProvider('participantKinds')]
    public function test_persisted_actor_roles_are_rechecked_even_when_authentication_is_stale(string $participant): void
    {
        $task = $this->task();
        $user = $participant === 'client' ? $task->assignment->project->client : $task->assignment->freelancer;
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_ADMIN]);
        $this->expectException(AuthorizationException::class);
        app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'done', 1);
    }

    public function test_changed_project_ownership_refuses_a_stale_client_save(): void
    {
        $task = $this->task();
        $this->actingAs($task->assignment->project->client);
        DB::table('projects')->where('id', $task->assignment->project_id)->update(['client_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id]);
        $this->expectException(AuthorizationException::class);
        app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details(), 1);
    }

    public function test_changed_assignee_refuses_the_former_freelancers_status_change(): void
    {
        $task = $this->task();
        $this->actingAs($task->assignment->freelancer);
        DB::table('project_assignments')->where('id', $task->project_assignment_id)->update(['freelancer_id' => User::factory()->create(['role' => User::ROLE_FREELANCER])->id]);
        $this->expectException(AuthorizationException::class);
        app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'done', 1);
    }

    #[DataProvider('guestActions')]
    public function test_task_identifiers_are_scoped_even_between_projects_with_the_same_client(string $action): void
    {
        $task = $this->task();
        $other = $this->assignment();
        $other->project->forceFill(['client_id' => $task->assignment->project->client_id])->save();
        $this->actingAs($task->assignment->project->client);
        $this->expectException(ModelNotFoundException::class);
        if ($action === 'details') {
            app(SaveProjectTask::class)->save($other->project_id, $task->id, $this->details(), 1);
        } else {
            app(ChangeProjectTaskStatus::class)->change($other->project_id, $task->id, 'done', 1);
        }
    }

    public function test_an_assignment_is_required_before_creating_tasks(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client);
        $this->expectException(ModelNotFoundException::class);
        app(SaveProjectTask::class)->save($project->id, null, $this->details());
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_details_leave_stored_task_and_versions_unchanged(string $field, mixed $value): void
    {
        $task = $this->task();
        $before = $task->getAttributes();
        $this->actingAs($task->assignment->project->client);
        $this->assertInvalid(fn () => app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details([$field => $value]), 1), $field);
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_maximum_text_lengths_are_accepted_and_plain_text_is_preserved(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $task = app(SaveProjectTask::class)->save($assignment->project_id, null, $this->details(['title' => str_repeat('T', 150), 'description' => str_repeat('D', 5000)]));
        $this->assertSame(150, strlen($task->title));
        $this->assertSame(5000, strlen($task->description));
        $task = app(SaveProjectTask::class)->save($assignment->project_id, $task->id, $this->details(['description' => "<script>alert(1)</script>\nSecond line"]), 1);
        $this->assertSame("<script>alert(1)</script>\nSecond line", $task->description);
    }

    #[DataProvider('allowedDates')]
    public function test_due_dates_include_assignment_boundaries_and_historical_overdue_dates(string $date): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $task = app(SaveProjectTask::class)->save($assignment->project_id, null, $this->details(['due_date' => $date]));
        $this->assertSame($date, $task->due_date->toDateString());
        $this->assertSame('2026-10-25', $assignment->fresh()->agreed_deadline->toDateString());
    }

    public function test_task_dates_use_agreed_terms_instead_of_the_original_project_deadline(): void
    {
        $assignment = $this->assignment();
        $assignment->project->update(['deadline' => '2026-10-22']);
        $this->actingAs($assignment->project->client);
        $task = app(SaveProjectTask::class)->save($assignment->project_id, null, $this->details(['due_date' => '2026-10-25']));
        $this->assertSame('2026-10-25', $task->due_date->toDateString());
    }

    public function test_selected_milestones_must_exist_and_belong_to_the_assignment(): void
    {
        $assignment = $this->assignment();
        $other = Milestone::factory()->create();
        $this->actingAs($assignment->project->client);
        foreach ([$other->id, 999999] as $id) {
            $this->assertInvalid(fn () => app(SaveProjectTask::class)->save($assignment->project_id, null, $this->details(['milestone_id' => $id])), 'milestone_id');
        }
        $this->assertDatabaseCount('project_tasks', 0);
    }

    public function test_task_due_date_cannot_exceed_selected_milestone_and_validation_is_rechecked_on_edit(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'due_date' => '2026-10-23']);
        $this->actingAs($assignment->project->client);
        $this->assertInvalid(fn () => app(SaveProjectTask::class)->save($assignment->project_id, null, $this->details(['milestone_id' => $milestone->id])), 'due_date');
        $task = app(SaveProjectTask::class)->save($assignment->project_id, null, $this->details(['due_date' => '2026-10-23', 'milestone_id' => $milestone->id]))->fresh();
        $before = $task->getAttributes();
        $this->assertInvalid(fn () => app(SaveProjectTask::class)->save($assignment->project_id, $task->id, $this->details(['milestone_id' => $milestone->id]), 1), 'due_date');
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_milestone_date_reduction_checks_all_attached_tasks_including_completed_tasks(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'due_date' => '2026-10-25']);
        $task = ProjectTask::factory()->create([
            'project_assignment_id' => $assignment->id, 'milestone_id' => $milestone->id, 'due_date' => '2026-10-24',
            'status' => ProjectTaskStatus::Done, 'completed_at' => '2026-10-23 10:00:00',
        ])->fresh();
        $other = ProjectTask::factory()->create(['project_assignment_id' => $assignment->id, 'milestone_id' => null, 'due_date' => '2026-10-25'])->fresh();
        $taskBefore = $task->getAttributes();
        $otherBefore = $other->getAttributes();
        $before = $milestone->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        $this->assertInvalid(fn () => app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, ['title' => 'Changed', 'description' => null, 'due_date' => '2026-10-23']), 'due_date');
        $this->assertSame($before, $milestone->fresh()->getAttributes());
        $saved = app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, ['title' => 'Changed', 'description' => null, 'due_date' => '2026-10-24']);
        $this->assertSame('2026-10-24', $saved->due_date->toDateString());
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
    }

    public function test_same_second_conflicting_details_are_refused_by_version_instead_of_timestamp(): void
    {
        $task = $this->task();
        $this->actingAs($task->assignment->project->client);
        $first = app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details(['title' => 'First editor']), 1)->fresh();
        $this->assertSame(2, $first->details_version);
        $this->assertSame($task->getRawOriginal('updated_at'), $first->getRawOriginal('updated_at'));
        $before = $first->getAttributes();
        $this->assertInvalid(fn () => app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details(['title' => 'Stale editor']), 1), 'task_conflict');
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertInvalid(fn () => app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details()), 'task_conflict');
    }

    public function test_unchanged_details_preserve_timestamps_and_versions(): void
    {
        $task = $this->task(['title' => 'Same title', 'description' => 'Same description', 'due_date' => '2026-10-24']);
        $before = $task->getAttributes();
        $this->actingAs($task->assignment->project->client);
        $this->travel(1)->hours();
        app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details(['title' => '  Same title  ', 'description' => 'Same description']), 1);
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    #[DataProvider('participantKinds')]
    public function test_both_participants_can_complete_retry_and_reopen_without_changing_project_terms(string $participant): void
    {
        $task = $this->task();
        $assignment = $task->assignment;
        $projectBefore = $assignment->project->fresh()->getAttributes();
        $assignmentBefore = $assignment->fresh()->getAttributes();
        $this->actingAs($participant === 'client' ? $assignment->project->client : $assignment->freelancer);
        $doing = app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'in_progress', 1)->fresh();
        $this->assertSame(ProjectTaskStatus::InProgress, $doing->status);
        $this->assertNull($doing->completed_at);
        $this->assertSame(2, $doing->status_version);
        $done = app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'done', 2)->fresh();
        $this->assertSame(ProjectTaskStatus::Done, $done->status);
        $this->assertSame('2026-10-23 12:00:00', $done->getRawOriginal('completed_at'));
        $this->assertSame(3, $done->status_version);
        $beforeRetry = $done->getAttributes();
        $this->travel(1)->hours();
        app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'done', 1);
        $this->assertSame($beforeRetry, $task->fresh()->getAttributes());
        $reopened = app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'todo', 3)->fresh();
        $this->assertSame(ProjectTaskStatus::Todo, $reopened->status);
        $this->assertNull($reopened->completed_at);
        $this->assertSame(4, $reopened->status_version);
        $again = app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'done', 4)->fresh();
        $this->assertSame('2026-10-23 13:00:00', $again->getRawOriginal('completed_at'));
        $this->assertSame(1, $again->details_version);
        $this->assertSame($projectBefore, $assignment->project->fresh()->getAttributes());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getAttributes());
    }

    public function test_status_noop_preserves_all_attributes_even_with_an_old_version(): void
    {
        $task = $this->task();
        $before = $task->getAttributes();
        $this->actingAs($task->assignment->freelancer);
        $this->travel(1)->hours();
        app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'todo', 0);
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_unsupported_status_is_rejected_without_writes(): void
    {
        $task = $this->task();
        $before = $task->getAttributes();
        $this->actingAs($task->assignment->freelancer);
        $this->assertInvalid(fn () => app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'approved', 1), 'task_status');
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_aba_status_changes_reject_an_old_conflicting_request(): void
    {
        $task = $this->task();
        $this->actingAs($task->assignment->freelancer);
        app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'in_progress', 1);
        $back = app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'todo', 2)->fresh();
        $before = $back->getAttributes();
        $this->assertInvalid(fn () => app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'done', 1), 'task_conflict');
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_details_and_status_updates_use_independent_versions_and_preserve_each_others_fields(): void
    {
        $task = $this->task();
        $this->actingAs($task->assignment->freelancer);
        $done = app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'done', 1)->fresh();
        $completion = $done->getRawOriginal('completed_at');
        $this->actingAs($task->assignment->project->client);
        $edited = app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details(['title' => 'Current detail edit']), 1)->fresh();
        $this->assertSame(ProjectTaskStatus::Done, $edited->status);
        $this->assertSame($completion, $edited->getRawOriginal('completed_at'));
        $this->assertSame(2, $edited->details_version);
        $this->assertSame(2, $edited->status_version);
        $this->actingAs($task->assignment->freelancer);
        $reopened = app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'in_progress', 2)->fresh();
        $this->assertSame('Current detail edit', $reopened->title);
        $this->assertSame(2, $reopened->details_version);
        $this->assertNull($reopened->completed_at);
    }

    #[DataProvider('readOnlyStatuses')]
    public function test_mutations_including_same_status_retries_are_refused_after_project_leaves_in_progress(ProjectStatus $status): void
    {
        $task = $this->task();
        $before = $task->getAttributes();
        $task->assignment->project->forceFill(['status' => $status])->save();
        $this->actingAs($task->assignment->project->client);
        $this->assertInvalid(fn () => app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details(), 1), 'plan');
        $this->assertInvalid(fn () => app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'todo', 1), 'plan');
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_project_status_is_rechecked_after_a_simulated_change_before_the_project_lock(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($assignment, &$changed): void {
            if (! $changed && str_contains($query->sql, 'from "users"')) {
                $changed = true;
                DB::table('projects')->where('id', $assignment->project_id)->update(['status' => 'completed']);
            }
        });
        $this->assertInvalid(fn () => app(SaveProjectTask::class)->save($assignment->project_id, null, $this->details()), 'plan');
        $this->assertTrue($changed);
        $this->assertDatabaseCount('project_tasks', 0);
    }

    public function test_false_model_saves_roll_back_task_detail_and_status_changes(): void
    {
        $task = $this->task();
        $before = $task->getAttributes();
        $this->actingAs($task->assignment->project->client);
        ProjectTask::saving(fn () => false);
        try {
            foreach (['details', 'status'] as $action) {
                try {
                    $this->perform($action, $task);
                    $this->fail('Expected failed task save.');
                } catch (RuntimeException) {
                    $this->assertSame($before, $task->fresh()->getAttributes());
                }
            }
        } finally {
            ProjectTask::flushEventListeners();
        }
    }

    public function test_exception_after_a_task_update_rolls_back_the_entire_mutation(): void
    {
        $task = $this->task();
        $before = $task->getAttributes();
        $assignmentBefore = $task->assignment->fresh()->getAttributes();
        $this->actingAs($task->assignment->freelancer);
        ProjectTask::saved(function (): void {
            throw new RuntimeException('Simulated failure after SQL update.');
        });
        try {
            app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'done', 1);
            $this->fail('Expected transactional failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after SQL update.', $exception->getMessage());
        } finally {
            ProjectTask::flushEventListeners();
        }
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($assignmentBefore, $task->assignment->fresh()->getAttributes());
    }

    private function assignment(): ProjectAssignment
    {
        return ProjectAssignment::factory()->create(['assigned_at' => '2026-10-20 18:30:00', 'agreed_deadline' => '2026-10-25']);
    }

    private function task(array $changes = []): ProjectTask
    {
        $assignment = $this->assignment();

        return ProjectTask::factory()->create(array_replace(['project_assignment_id' => $assignment->id, 'due_date' => '2026-10-24'], $changes))->fresh();
    }

    private function details(array $changes = []): array
    {
        return array_replace(['title' => 'Review the brief', 'description' => 'Confirm the intended deliverables.', 'due_date' => '2026-10-24', 'milestone_id' => null], $changes);
    }

    private function perform(string $action, ProjectTask $task): void
    {
        if ($action === 'details') {
            app(SaveProjectTask::class)->save($task->assignment->project_id, $task->id, $this->details(['title' => 'Changed details']), $task->details_version);
        } else {
            app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'done', $task->status_version);
        }
    }

    private function assertInvalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation failure for '.$field.'.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function guestActions(): array
    {
        return [['details'], ['status']];
    }

    public static function unrelatedActors(): array
    {
        $actors = [];
        foreach ([User::ROLE_CLIENT, User::ROLE_FREELANCER, User::ROLE_ADMIN] as $role) {
            foreach (['details', 'status'] as $action) {
                $actors[] = [$role, $action];
            }
        }

        return $actors;
    }

    public static function participantKinds(): array
    {
        return [['client'], ['freelancer']];
    }

    public static function invalidDetails(): array
    {
        return [
            ['title', ''], ['title', " \t\n"], ['title', "\u{00A0}\u{2002}"], ['title', str_repeat('T', 151)],
            ['description', str_repeat('D', 5001)], ['description', []], ['due_date', ''], ['due_date', 'invalid'],
            ['due_date', '2026-02-30'], ['due_date', '2026-10-20'], ['due_date', '2026-10-26'], ['due_date', '2026-10-24 12:00:00'],
            ['milestone_id', 'invalid'], ['milestone_id', []],
        ];
    }

    public static function allowedDates(): array
    {
        return [['2026-10-21'], ['2026-10-22'], ['2026-10-23'], ['2026-10-25']];
    }

    public static function readOnlyStatuses(): array
    {
        return array_map(fn ($status) => [$status], array_filter(ProjectStatus::cases(), fn ($status) => $status !== ProjectStatus::InProgress));
    }
}
