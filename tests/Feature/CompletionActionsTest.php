<?php

namespace Tests\Feature;

use App\Actions\Milestones\CompleteMilestone;
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
use App\Support\CompletionReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CompletionActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 18:45:00', 'UTC'));
        Storage::fake('deliverables');
    }

    public function test_owner_explicitly_completes_ready_milestone_with_trusted_metadata_and_preserved_work(): void
    {
        $milestone = Milestone::factory()->create();
        $assignment = $milestone->assignment;
        $task = ProjectTask::factory()->create(['milestone_id' => $milestone->id, 'status' => ProjectTaskStatus::Done]);
        $version = $this->approved($assignment, $milestone);
        $before = $version->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        $completed = $this->milestone($milestone, ['completion_note' => "  Outcome\n<script>unsafe</script>  ", 'completed_at' => '2000-01-01', 'completed_by' => 999999, 'project_assignment_id' => 999999, 'status' => 'completed']);
        $this->assertTrue($completed->isCompleted());
        $this->assertSame($assignment->project->client_id, $completed->completed_by);
        $this->assertSame('2026-10-25 18:45:00', $completed->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame("Outcome\n<script>unsafe</script>", $completed->completion_note);
        $this->assertSame($before, $version->fresh()->getAttributes());
        $this->assertSame(ProjectTaskStatus::Done, $task->fresh()->status);
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
        Storage::disk('deliverables')->assertExists($version->storage_path);
    }

    public function test_project_completion_preserves_assignment_terms_files_reviews_and_original_brief(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->completed()->create(['project_assignment_id' => $assignment->id]);
        $version = $this->approved($assignment, $milestone);
        ProjectTask::factory()->create(['project_assignment_id' => $assignment->id, 'status' => ProjectTaskStatus::Done]);
        $before = $assignment->fresh()->getAttributes();
        $work = $version->fresh()->getAttributes();
        $review = $version->review->getAttributes();
        $projectBefore = $assignment->project->fresh();
        $this->actingAs($projectBefore->client);
        $project = $this->project($assignment, ['completion_note' => '  Finished the agreed outcome.  ', 'completed_by' => 999999, 'completed_at' => '2000-01-01', 'status' => 'cancelled', 'client_id' => 999999]);
        $this->assertSame(ProjectStatus::Completed, $project->status);
        $this->assertSame($projectBefore->client_id, $project->completed_by);
        $this->assertSame('Finished the agreed outcome.', $project->completion_note);
        $this->assertSame('2026-10-25 18:45:00', $project->completed_at->format('Y-m-d H:i:s'));
        foreach (['title', 'description', 'budget', 'deadline', 'client_id', 'published_at'] as $field) {
            $this->assertEquals($projectBefore->{$field}, $project->{$field});
        }
        $this->assertSame($before, $assignment->fresh()->getAttributes());
        $this->assertSame($work, $version->fresh()->getAttributes());
        $this->assertSame($review, $version->review->fresh()->getAttributes());
        $this->assertSame('Private original work.', Storage::disk('deliverables')->get($version->storage_path));
    }

    #[DataProvider('pendingWork')]
    public function test_project_readiness_refuses_all_outstanding_requirements(string $work): void
    {
        $assignment = ProjectAssignment::factory()->create();
        if ($work !== 'missing_deliverable') {
            $version = $this->approved($assignment);
        }
        match ($work) {
            'milestone' => Milestone::factory()->create(['project_assignment_id' => $assignment->id]),
            'ungrouped_task' => ProjectTask::factory()->create(['project_assignment_id' => $assignment->id]),
            'attached_task' => ProjectTask::factory()->create(['milestone_id' => Milestone::factory()->completed()->create(['project_assignment_id' => $assignment->id])->id]),
            'unreviewed' => DeliverableVersion::factory()->create(['deliverable_id' => $version->deliverable_id]),
            'changes_requested' => DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => DeliverableVersion::factory()->create(['deliverable_id' => $version->deliverable_id])->id]),
            'no_version' => Deliverable::factory()->create(['project_assignment_id' => $assignment->id]),
            default => null,
        };
        $this->assertFalse(CompletionReadiness::forProject($assignment)['ready']);
        $this->actingAs($assignment->project->client);
        $before = $assignment->project->fresh()->getAttributes();
        $this->invalid(fn () => $this->project($assignment), 'completion');
        $this->assertSame($before, $assignment->project->fresh()->getAttributes());
    }

    #[DataProvider('milestoneWork')]
    public function test_milestone_refuses_pending_or_unapproved_attached_work(string $work): void
    {
        $milestone = Milestone::factory()->create();
        if ($work === 'task') {
            ProjectTask::factory()->create(['milestone_id' => $milestone->id]);
        } else {
            $deliverable = Deliverable::factory()->create(['project_assignment_id' => $milestone->project_assignment_id, 'milestone_id' => $milestone->id]);
            if ($work !== 'no_version') {
                $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
                if ($work === 'changes_requested') {
                    DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $version->id]);
                }
            }
        }
        $this->actingAs($milestone->assignment->project->client);
        $this->invalid(fn () => $this->milestone($milestone), 'completion');
        $this->assertNull($milestone->fresh()->completed_at);
    }

    #[DataProvider('badEmptyNotes')]
    public function test_empty_milestone_requires_a_meaningful_outcome_note(mixed $note): void
    {
        $milestone = Milestone::factory()->create();
        $this->actingAs($milestone->assignment->project->client);
        $this->invalid(fn () => $this->milestone($milestone, ['completion_note' => $note]), 'completion_note');
        $this->assertNull($milestone->fresh()->completed_at);
    }

    public function test_empty_milestone_accepts_trimmed_outcome_and_unrelated_pending_work_does_not_block_it(): void
    {
        $milestone = Milestone::factory()->create();
        ProjectTask::factory()->create(['project_assignment_id' => $milestone->project_assignment_id]);
        $this->actingAs($milestone->assignment->project->client);
        $this->assertTrue($this->milestone($milestone, ['completion_note' => "  Agreed planning outcome delivered. \n"])->isCompleted());
        $this->assertFalse(CompletionReadiness::forProject($milestone->assignment)['ready']);
    }

    #[DataProvider('completionKinds')]
    public function test_confirmation_note_limits_exact_retries_and_conflicts(string $kind): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $version = $this->approved($assignment);
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->project->client);
        $call = fn ($input) => $kind === 'milestone' ? $this->milestone($milestone, $input) : $this->project($assignment, $input);
        // Complete the empty milestone first for the project readiness prerequisite.
        if ($kind === 'project') {
            $this->milestone($milestone, ['completion_note' => 'Planning outcome complete.']);
        }
        $this->invalid(fn () => $call(['confirmed' => false, 'completion_note' => 'Outcome']), 'confirmed');
        $this->invalid(fn () => $call(['completion_note' => str_repeat('x', 2001)]), 'completion_note');
        $record = $call(['completion_note' => str_repeat('x', 2000)])->fresh();
        $before = $record->getAttributes();
        $this->travel(2)->days();
        $retry = $call(['completion_note' => '  '.str_repeat('x', 2000).'  '])->fresh();
        $this->assertSame($before, $retry->getAttributes());
        $this->invalid(fn () => $call(['completion_note' => 'Changed outcome']), 'completion');
        $this->assertSame($before, $record->fresh()->getAttributes());
        Storage::disk('deliverables')->assertExists($version->storage_path);
    }

    #[DataProvider('closedStatuses')]
    public function test_new_completion_refuses_other_project_states_and_does_not_backfill_legacy_completed_records(ProjectStatus $status): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->approved($assignment);
        $assignment->project->forceFill(['status' => $status])->save();
        $this->actingAs($assignment->project->client);
        $this->invalid(fn () => $this->milestone($milestone, ['completion_note' => 'Outcome']), 'completion');
        $this->invalid(fn () => $this->project($assignment), 'completion');
        $this->assertNull($assignment->project->fresh()->completed_at);
        $this->assertNull($milestone->fresh()->completed_at);
    }

    #[DataProvider('completionKinds')]
    public function test_failure_after_update_rolls_back_all_completion_metadata(string $kind): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->approved($assignment);
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        if ($kind === 'project') {
            $milestone->forceFill(['completed_at' => now(), 'completed_by' => $assignment->project->client_id])->save();
        }
        $class = $kind === 'milestone' ? Milestone::class : Project::class;
        $class::updated(function ($record): void {
            if ($record->completed_at !== null) {
                throw new RuntimeException('Injected completion failure');
            }
        });
        $this->actingAs($assignment->project->client);
        try {
            $kind === 'milestone' ? $this->milestone($milestone, ['completion_note' => 'Outcome']) : $this->project($assignment);
            $this->fail('The failed completion must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected completion failure', $exception->getMessage());
        }
        $record = $kind === 'milestone' ? $milestone->fresh() : $assignment->project->fresh();
        $this->assertNull($record->completed_at);
        $this->assertNull($record->completed_by);
        $this->assertNull($record->completion_note);
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
    }

    public function test_simulated_new_work_after_readiness_read_is_rechecked_at_confirmation(): void
    {
        // Sequential interleaving, not parallel MySQL execution.
        $assignment = ProjectAssignment::factory()->create();
        $this->approved($assignment);
        $this->assertTrue(CompletionReadiness::forProject($assignment)['ready']);
        ProjectTask::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->project->client);
        $this->invalid(fn () => $this->project($assignment), 'completion');
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
    }

    public function test_simulated_new_version_after_ready_read_requires_its_own_approval(): void
    {
        // Sequential upload-before-confirmation simulation, never a development database race.
        $assignment = ProjectAssignment::factory()->create();
        $version = $this->approved($assignment);
        $this->assertTrue(CompletionReadiness::forProject($assignment)['ready']);
        DeliverableVersion::factory()->create(['deliverable_id' => $version->deliverable_id]);
        $this->actingAs($assignment->project->client);
        $this->invalid(fn () => $this->project($assignment), 'completion');
        $this->assertDatabaseCount('deliverable_reviews', 1);
        $this->assertNull($assignment->project->fresh()->completed_at);
    }

    public function test_milestone_retry_after_project_completion_preserves_its_original_outcome(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->approved($assignment);
        $this->actingAs($assignment->project->client);
        $first = $this->milestone($milestone, ['completion_note' => 'Planning delivered.'])->fresh();
        $before = $first->getAttributes();
        $this->project($assignment);
        $this->travel(1)->days();
        $retry = $this->milestone($milestone, ['completion_note' => 'Planning delivered.'])->fresh();
        $this->assertSame($before, $retry->getAttributes());
    }

    public function test_new_owner_cannot_impersonate_an_original_completion_retry(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->approved($assignment);
        $this->actingAs($assignment->project->client);
        $project = $this->project($assignment, ['completion_note' => 'Original outcome.'])->fresh();
        $originalActor = $project->completed_by;
        $newOwner = User::factory()->create(['role' => User::ROLE_CLIENT]);
        DB::table('projects')->where('id', $project->id)->update(['client_id' => $newOwner->id]);
        $this->actingAs($newOwner);
        $this->invalid(fn () => $this->project($assignment, ['completion_note' => 'Original outcome.']), 'completion');
        $this->assertSame($originalActor, $project->fresh()->completed_by);
    }

    private function approved(ProjectAssignment $assignment, ?Milestone $milestone = null): DeliverableVersion
    {
        $deliverable = Deliverable::factory()->create(['project_assignment_id' => $assignment->id, 'milestone_id' => $milestone?->id]);
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        DeliverableReview::factory()->create(['deliverable_version_id' => $version->id]);
        Storage::disk('deliverables')->put($version->storage_path, 'Private original work.');

        return $version->fresh();
    }

    private function milestone(Milestone $milestone, array $input = []): Milestone
    {
        return app(CompleteMilestone::class)->complete($milestone->assignment->project_id, $milestone->id, array_replace(['confirmed' => true], $input));
    }

    private function project(ProjectAssignment $assignment, array $input = []): Project
    {
        return app(CompleteProject::class)->complete($assignment->project_id, array_replace(['confirmed' => true], $input));
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('The invalid completion must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function pendingWork(): array
    {
        return array_map(fn ($case) => [$case], ['missing_deliverable', 'milestone', 'ungrouped_task', 'attached_task', 'unreviewed', 'changes_requested', 'no_version']);
    }

    public static function milestoneWork(): array
    {
        return [['task'], ['unreviewed'], ['changes_requested'], ['no_version']];
    }

    public static function badEmptyNotes(): array
    {
        return [[null], [''], [" \n\t "], ["\u{200B}\u{00A0}"], [[]], [str_repeat('x', 2001)]];
    }

    public static function completionKinds(): array
    {
        return [['milestone'], ['project']];
    }

    public static function closedStatuses(): array
    {
        return array_map(fn ($status) => [$status], [ProjectStatus::Draft, ProjectStatus::Open, ProjectStatus::Completed, ProjectStatus::Cancelled]);
    }
}
