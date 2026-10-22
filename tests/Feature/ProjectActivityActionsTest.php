<?php

namespace Tests\Feature;

use App\Actions\Milestones\SaveMilestone;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Enums\ProjectActivityEvent as Event;
use App\Enums\ProjectStatus;
use App\Models\InAppNotification;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use App\Services\ProjectActivities;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsProjectActivity;
use Tests\TestCase;

class ProjectActivityActionsTest extends TestCase
{
    use BuildsProjectActivity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 18:45:00', 'UTC'));
        Storage::fake('deliverables');
    }

    #[DataProvider('activityEvents')]
    public function test_effective_authorized_changes_emit_safe_activity_and_record_retries_are_idempotent(Event $event): void
    {
        $run = $this->activityOperation($event);
        $before = ProjectActivity::count();
        $run();
        $this->assertSame($before + 1, ProjectActivity::count());
        $activity = ProjectActivity::latest('id')->firstOrFail();
        $this->assertSame($event, $activity->event_type);
        $this->assertSame(auth()->id(), $activity->actor_id);
        $this->assertSame('UTC', $activity->occurred_at->timezoneName);
        $this->assertSame($event === Event::Published, $activity->project_assignment_id === null);
        $this->assertNotNull($activity->project);
        $this->assertEqualsCanonicalizing(
            $event === Event::TaskStatusChanged ? ['record_id', 'revision', 'task_status']
                : (in_array($event, [Event::MilestoneEdited, Event::TaskEdited], true) ? ['record_id', 'revision'] : ['record_id']),
            array_keys($activity->metadata),
        );
        $this->assertStringNotContainsString('Sensitive', $activity->toJson());
        $snapshot = $activity->getAttributes();
        $alerts = InAppNotification::orderBy('id')->get()->toJson();
        $this->travel(10)->seconds();
        $origin = $event->modelClass()::findOrFail($activity->metadata['record_id']);
        $retry = DB::transaction(fn () => app(ProjectActivities::class)->record($event, $origin, auth()->user()));
        $this->assertSame($snapshot, $retry->getAttributes());
        if (! in_array($event, [Event::MilestoneCreated, Event::TaskCreated], true)) {
            $run();
        }
        $this->assertSame($before + 1, ProjectActivity::count());
        $this->assertSame($snapshot, $activity->fresh()->getAttributes());
        $this->assertSame($alerts, InAppNotification::orderBy('id')->get()->toJson());
    }

    #[DataProvider('activityEvents')]
    public function test_activity_failure_rolls_back_business_records_notifications_and_uploaded_files(Event $event): void
    {
        $run = $this->activityOperation($event);
        $tables = ['projects', 'proposals', 'project_assignments', 'milestones', 'project_tasks', 'deliverables', 'deliverable_versions', 'deliverable_reviews', 'payment_reports', 'payment_acknowledgments', 'dispute_cases', 'active_disputes', 'dispute_resolutions', 'notifications', 'project_activities'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy($table === 'active_disputes' ? 'project_assignment_id' : 'id')->get()->toJson();
        }
        $files = Storage::disk('deliverables')->allFiles();
        ProjectActivity::creating(fn () => false);
        try {
            $run();
            $this->fail('Activity failure must roll back the originating change.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Project activity could not be recorded.', $exception->getMessage());
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy($table === 'active_disputes' ? 'project_assignment_id' : 'id')->get()->toJson(), $table);
        }
        $this->assertSame($files, Storage::disk('deliverables')->allFiles());
    }

    public function test_unchanged_details_and_rejected_stale_task_saves_do_not_emit_but_real_edit_cycles_do(): void
    {
        $assignment = ProjectAssignment::factory()->create(['agreed_deadline' => '2026-12-01']);
        $this->actingAs($assignment->project->client);
        $input = ['title' => 'Original', 'description' => null, 'due_date' => '2026-11-10'];
        $milestone = app(SaveMilestone::class)->save($assignment->project_id, null, $input);
        $task = app(SaveProjectTask::class)->save($assignment->project_id, null, $input);
        $snapshot = ProjectActivity::orderBy('id')->get()->toJson();
        $this->travel(5)->seconds();
        app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, $input);
        app(SaveProjectTask::class)->save($assignment->project_id, $task->id, $input, 1);
        $this->assertSame($snapshot, ProjectActivity::orderBy('id')->get()->toJson());
        foreach (['Changed', 'Original', 'Changed'] as $index => $title) {
            app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, array_replace($input, ['title' => $title, 'details_version' => 999]));
            app(SaveProjectTask::class)->save($assignment->project_id, $task->id, array_replace($input, ['title' => $title]), $index + 1);
        }
        $this->assertSame(3, $milestone->fresh()->details_version);
        $this->assertSame(3, ProjectActivity::where('event_type', Event::MilestoneEdited)->count());
        $this->assertSame(3, ProjectActivity::where('event_type', Event::TaskEdited)->count());
        $before = ProjectActivity::count();
        try {
            app(SaveProjectTask::class)->save($assignment->project_id, $task->id, $input, 0);
            $this->fail('Stale details must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('task_conflict', $exception->errors());
        }
        $this->assertSame($before, ProjectActivity::count());
    }

    #[DataProvider('activityEvents')]
    public function test_suspended_actors_cannot_mutate_or_emit_activity(Event $event): void
    {
        $run = $this->activityOperation($event);
        $before = ProjectActivity::get()->toJson();
        auth()->user()->newQuery()->whereKey(auth()->id())->update(['suspended_at' => now('UTC'), 'access_version' => 1]);
        try {
            $run();
            $this->fail('Suspended actors cannot make business changes.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, ProjectActivity::get()->toJson());
    }

    public function test_done_reopened_done_has_three_events_even_at_the_same_second_and_unchanged_retries_have_none(): void
    {
        $task = ProjectTask::factory()->create();
        $assignment = $task->assignment;
        $this->actingAs($assignment->freelancer);
        foreach (['done', 'todo', 'done'] as $version => $status) {
            $saved = app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, $status, $version + 1);
            $snapshot = $saved->getAttributes();
            app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, $status, $version + 1);
            $this->assertSame($snapshot, $task->fresh()->getAttributes());
        }
        $events = ProjectActivity::orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame(['done', 'todo', 'done'], $events->pluck('metadata.task_status')->all());
        $this->assertSame([2, 3, 4], $events->pluck('metadata.revision')->all());
        $this->assertSame(1, $events->pluck('occurred_at')->unique()->count());
        try {
            app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'todo', 0);
            $this->fail('Stale status must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('task_conflict', $exception->errors());
        }
        $assignment->project->forceFill(['status' => ProjectStatus::Completed])->save();
        try {
            app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'todo', 4);
            $this->fail('Finished projects cannot mutate tasks.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $this->assertDatabaseCount('project_activities', 3);
    }
}
