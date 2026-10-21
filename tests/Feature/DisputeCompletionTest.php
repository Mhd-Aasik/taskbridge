<?php

namespace Tests\Feature;

use App\Actions\Disputes\OpenDispute;
use App\Actions\Disputes\ResolveDispute;
use App\Actions\Milestones\CompleteMilestone;
use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Actions\Projects\CompleteProject;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\Milestone;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Support\CompletionReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DisputeCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 18:45:00', 'UTC'));
        Storage::fake('deliverables');
    }

    public function test_active_case_blocks_new_completions_then_resolution_unblocks_without_other_writes(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $deliverable = Deliverable::factory()->create(['project_assignment_id' => $assignment->id]);
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        DeliverableReview::factory()->create(['deliverable_version_id' => $version->id]);
        Storage::disk('deliverables')->put($version->storage_path, 'Preserved private work');
        $this->actingAs($assignment->project->client);
        $case = $this->open($assignment);
        $this->assertFalse(CompletionReadiness::forMilestone($milestone->fresh())['ready']);
        $this->assertContains('An active dispute must be resolved or dismissed before new completion.', CompletionReadiness::forProject($assignment->fresh())['reasons']);
        foreach (['milestone', 'project'] as $kind) {
            try {
                $kind === 'milestone'
                    ? app(CompleteMilestone::class)->complete($assignment->project_id, $milestone->id, ['completion_note' => 'Outcome accepted', 'confirmed' => true])
                    : app(CompleteProject::class)->complete($assignment->project_id, ['confirmed' => true]);
                $this->fail('Active cases must block new completion.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('active dispute', implode(' ', $exception->errors()['completion']));
            }
        }
        // Payment reporting and acknowledgment remain permitted while the case is open.
        $payment = app(ReportPayment::class)->report($assignment->project_id, ['confirmed' => true, 'method' => 'cash', 'paid_on' => '2026-10-28'], (string) Str::uuid());
        $this->actingAs($assignment->freelancer);
        app(AcknowledgePayment::class)->acknowledge($assignment->project_id, $payment->id, ['confirmed' => true]);
        $before = [];
        foreach ([$assignment, $assignment->project, $milestone, $version, $version->review, $payment, $payment->fresh()->acknowledgment] as $record) {
            $before[$record->getTable().':'.$record->id] = $record->fresh()->getAttributes();
        }
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        app(ResolveDispute::class)->resolve($case->id, ['decision' => 'dismissed', 'rationale' => 'The evidence is clarified.', 'confirmed' => true]);
        foreach ([$assignment, $assignment->project, $milestone, $version, $version->review, $payment, $payment->fresh()->acknowledgment] as $record) {
            $this->assertSame($before[$record->getTable().':'.$record->id], $record->fresh()->getAttributes());
        }
        $this->assertSame('Preserved private work', Storage::disk('deliverables')->get($version->storage_path));
        $this->assertTrue(CompletionReadiness::forMilestone($milestone->fresh())['ready']);
        $this->actingAs($assignment->project->client);
        app(CompleteMilestone::class)->complete($assignment->project_id, $milestone->id, ['completion_note' => 'Outcome accepted', 'confirmed' => true]);
        $this->assertTrue(CompletionReadiness::forProject($assignment->fresh())['ready']);
        $this->assertSame(ProjectStatus::Completed, app(CompleteProject::class)->complete($assignment->project_id, ['confirmed' => true])->status);
    }

    public function test_opening_after_completion_preserves_metadata_and_exact_completion_retries(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->completed()->create(['project_assignment_id' => $assignment->id, 'completed_by' => $assignment->project->client_id, 'completion_note' => 'Original outcome']);
        $assignment->project->forceFill(['status' => ProjectStatus::Completed, 'completed_at' => now()->subDay(), 'completed_by' => $assignment->project->client_id, 'completion_note' => 'Original completion'])->save();
        $before = $assignment->project->fresh()->getAttributes();
        $milestoneBefore = $milestone->fresh()->getAttributes();
        $this->actingAs($assignment->freelancer);
        $this->open($assignment);
        $this->actingAs($assignment->project->client);
        app(CompleteProject::class)->complete($assignment->project_id, ['completion_note' => 'Original completion', 'confirmed' => true]);
        app(CompleteMilestone::class)->complete($assignment->project_id, $milestone->id, ['completion_note' => 'Original outcome', 'confirmed' => true]);
        $this->assertSame($before, $assignment->project->fresh()->getAttributes());
        $this->assertSame($milestoneBefore, $milestone->fresh()->getAttributes());
        $this->get(route('projects.completion', $assignment->project_id))->assertOk()->assertSeeText('active dispute')->assertSeeText('Original completion');
    }

    public function test_open_case_does_not_block_normal_task_work_or_imply_completion(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $case = $this->open($assignment);
        $task = app(SaveProjectTask::class)->save($assignment->project_id, null, ['title' => 'Continue agreed work', 'description' => 'An open case does not freeze work.', 'due_date' => $assignment->agreed_deadline->toDateString(), 'milestone_id' => null]);
        $this->actingAs($assignment->freelancer);
        $done = app(ChangeProjectTaskStatus::class)->change($assignment->project_id, $task->id, 'done', $task->status_version);
        $this->assertNotNull($done->completed_at);
        $this->assertTrue($case->fresh()->isOpen());
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
        $this->assertNull($assignment->project->fresh()->completed_at);
    }

    private function open(ProjectAssignment $assignment): DisputeCase
    {
        return app(OpenDispute::class)->open($assignment->project_id, ['title' => 'Review the outcome', 'reason' => 'Please clarify the delivered outcome.', 'confirmed' => true], (string) Str::uuid());
    }
}
