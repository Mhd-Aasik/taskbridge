<?php

namespace Tests\Feature;

use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Actions\Milestones\CompleteMilestone;
use App\Actions\Milestones\SaveMilestone;
use App\Actions\Projects\CompleteProject;
use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Enums\ProjectTaskStatus;
use App\Models\Deliverable;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\Milestone;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompletedWorkProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('deliverables');
    }

    #[DataProvider('lockedMutations')]
    public function test_completed_milestone_and_project_refuse_stale_work_mutations(bool $projectCompleted, string $mutation): void
    {
        [$assignment, $milestone, $task, $version] = $this->completedWork($projectCompleted);
        $before = $milestone->fresh()->getAttributes();
        $taskBefore = $task->fresh()->getAttributes();
        $projectBefore = $assignment->project->fresh()->getAttributes();
        $details = ['title' => 'Changed', 'description' => 'New text', 'due_date' => $milestone->due_date->toDateString(), 'milestone_id' => $milestone->id];
        $this->actingAs($assignment->project->client);
        if (str_starts_with($mutation, 'upload') || $mutation === 'freelancer_status') {
            $this->actingAs($assignment->freelancer);
        }
        try {
            match ($mutation) {
                'milestone_details' => app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, $details),
                'add_task' => app(SaveProjectTask::class)->save($assignment->project_id, null, $details),
                'edit_task' => app(SaveProjectTask::class)->save($assignment->project_id, $task->id, $details, $task->details_version),
                'move_out' => app(SaveProjectTask::class)->save($assignment->project_id, $task->id, array_replace($details, ['milestone_id' => null]), $task->details_version),
                'move_in' => $this->moveInto($assignment, $details),
                'client_status', 'freelancer_status' => app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'todo', $task->status_version),
                'upload_new' => app(SubmitDeliverable::class)->submit($assignment->project_id, null, ['title' => 'New work', 'milestone_id' => $milestone->id], $this->file(), (string) Str::uuid()),
                'upload_revision' => app(SubmitDeliverable::class)->submit($assignment->project_id, $version->deliverable_id, [], $this->file(), (string) Str::uuid()),
            };
            $this->fail('Completed work must reject the stale mutation: '.$mutation);
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame($before, $milestone->fresh()->getAttributes());
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
        $this->assertSame($projectBefore, $assignment->project->fresh()->getAttributes());
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$version->storage_path], Storage::disk('deliverables')->allFiles());
    }

    public function test_milestone_completion_does_not_lock_other_milestones_or_ungrouped_work(): void
    {
        [$assignment] = $this->completedWork();
        $this->actingAs($assignment->project->client);
        $milestone = app(SaveMilestone::class)->save($assignment->project_id, null, ['title' => 'Next outcome', 'due_date' => $assignment->agreed_deadline->toDateString()]);
        $task = app(SaveProjectTask::class)->save($assignment->project_id, null, ['title' => 'Other task', 'due_date' => $assignment->agreed_deadline->toDateString()]);
        $this->actingAs($assignment->freelancer);
        $changed = app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'in_progress', $task->status_version);
        $version = app(SubmitDeliverable::class)->submit($assignment->project_id, null, ['title' => 'Other deliverable', 'milestone_id' => $milestone->id], $this->file(), (string) Str::uuid());
        $this->assertSame(ProjectTaskStatus::InProgress, $changed->status);
        $this->assertSame($milestone->id, $version->deliverable->milestone_id);
    }

    #[DataProvider('completionScopes')]
    public function test_new_review_is_refused_in_completed_work_but_original_review_and_upload_retries_are_read_only(bool $projectCompleted): void
    {
        [$assignment, $milestone, , $version] = $this->completedWork($projectCompleted);
        $originalReview = $version->review->fresh();
        $before = $originalReview->getAttributes();
        $this->actingAs($assignment->project->client);
        $retry = app(ReviewDeliverableVersion::class)->approve($assignment->project_id, $version->deliverable_id, $version->id, ['confirmed' => true]);
        $this->assertSame($before, $retry->getAttributes());
        // Trusted fixture simulates inconsistent legacy work; the action still refuses a new review.
        $extra = DeliverableVersion::factory()->create(['deliverable_id' => $version->deliverable_id]);
        try {
            app(ReviewDeliverableVersion::class)->approve($assignment->project_id, $version->deliverable_id, $extra->id, ['confirmed' => true]);
            $this->fail('Completed work cannot receive a new review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->actingAs($assignment->freelancer);
        $uploadRetry = app(SubmitDeliverable::class)->submit($assignment->project_id, $version->deliverable_id, [], null, $version->submission_id);
        $this->assertTrue($uploadRetry->is($version));
        $this->assertSame($before, $originalReview->fresh()->getAttributes());
    }

    public function test_completed_project_rejects_new_planning_submissions_and_competing_acceptance(): void
    {
        [$assignment] = $this->completedWork(true);
        $this->actingAs($assignment->project->client);
        try {
            app(SaveMilestone::class)->save($assignment->project_id, null, ['title' => 'New plan', 'due_date' => $assignment->agreed_deadline->toDateString()]);
            $this->fail('New planning must be refused.');
        } catch (ValidationException) {
            $this->assertSame(1, $assignment->milestones()->count());
        }
        $proposal = Proposal::factory()->create(['project_id' => $assignment->project_id]);
        try {
            app(AcceptProposal::class)->accept($assignment->project_id, $proposal->id, true);
            $this->fail('A different selection cannot replace the completed assignment.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('project_assignments', 1);
        }
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]));
        try {
            app(SubmitProposal::class)->submit($assignment->project_id, ['cover_letter' => 'New offer']);
            $this->fail('New proposals must be refused.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('proposals', 2);
        }
    }

    private function completedWork(bool $completeProject = false): array
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $task = ProjectTask::factory()->create(['milestone_id' => $milestone->id, 'status' => ProjectTaskStatus::Done]);
        $deliverable = Deliverable::factory()->create(['project_assignment_id' => $assignment->id, 'milestone_id' => $milestone->id]);
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        DeliverableReview::factory()->create(['deliverable_version_id' => $version->id]);
        Storage::disk('deliverables')->put($version->storage_path, 'Preserved private file.');
        $this->actingAs($assignment->project->client);
        app(CompleteMilestone::class)->complete($assignment->project_id, $milestone->id, ['confirmed' => true]);
        if ($completeProject) {
            app(CompleteProject::class)->complete($assignment->project_id, ['confirmed' => true]);
        }

        return [$assignment, $milestone->fresh(), $task->fresh(), $version->fresh()];
    }

    private function moveInto(ProjectAssignment $assignment, array $details): void
    {
        $task = ProjectTask::factory()->create(['project_assignment_id' => $assignment->id]);
        app(SaveProjectTask::class)->save($assignment->project_id, $task->id, $details, $task->details_version);
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('work.txt', 'Private new file.');
    }

    public static function lockedMutations(): array
    {
        $cases = [];
        foreach ([false, true] as $scope) {
            foreach (['milestone_details', 'add_task', 'edit_task', 'move_out', 'move_in', 'client_status', 'freelancer_status', 'upload_new', 'upload_revision'] as $mutation) {
                $cases[] = [$scope, $mutation];
            }
        }

        return $cases;
    }

    public static function completionScopes(): array
    {
        return [[false], [true]];
    }
}
