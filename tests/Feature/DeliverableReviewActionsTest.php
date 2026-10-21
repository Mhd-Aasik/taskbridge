<?php

namespace Tests\Feature;

use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Enums\DeliverableReviewDecision;
use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\ProjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeliverableReviewActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'));
        Storage::fake('deliverables');
    }

    public function test_approval_uses_server_metadata_and_preserves_the_submitted_work_and_project_terms(): void
    {
        $version = $this->version();
        $assignment = $version->deliverable->assignment;
        $projectBefore = $assignment->project->getAttributes();
        $assignmentBefore = $assignment->getAttributes();
        $deliverableBefore = $version->deliverable->getAttributes();
        $versionBefore = $version->getAttributes();
        $bytes = Storage::disk('deliverables')->get($version->storage_path);

        $review = $this->review($version, 'approve', [
            'remarks' => "  Good work.\nReady for the next step.  ",
            'reviewed_by' => 999999, 'deliverable_version_id' => 999999,
            'decision' => 'changes_requested', 'reviewed_at' => '2000-01-01',
            'created_at' => '2000-01-01', 'updated_at' => '2000-01-01',
            'id' => 999999, 'role' => User::ROLE_ADMIN, 'status' => 'completed',
        ])->fresh();

        $this->assertSame($version->id, $review->deliverable_version_id);
        $this->assertSame($assignment->project->client_id, $review->reviewed_by);
        $this->assertSame(DeliverableReviewDecision::Approved, $review->decision);
        $this->assertSame("Good work.\nReady for the next step.", $review->remarks);
        $this->assertSame('2026-10-24 12:00:00', $review->reviewed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-24 12:00:00', $review->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotSame(999999, $review->id);
        $this->assertSame($projectBefore, $assignment->project->fresh()->getAttributes());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getAttributes());
        $this->assertSame($deliverableBefore, $version->deliverable->fresh()->getAttributes());
        $this->assertSame($versionBefore, $version->fresh()->getAttributes());
        $this->assertSame($bytes, Storage::disk('deliverables')->get($version->storage_path));
        $this->assertSame(User::ROLE_CLIENT, $assignment->project->client->fresh()->role);
        $this->assertSame('approved', $version->deliverable->fresh()->reviewState());
        $this->assertTrue($version->deliverable->fresh()->isApproved());
    }

    public function test_changes_request_trims_meaningful_plain_text_and_uses_the_server_decision(): void
    {
        $version = $this->version();
        $review = $this->review($version, 'requestChanges', [
            'remarks' => " \nPlease revise <script>alert(1)</script>.\nKeep the earlier files.\t ",
            'reviewed_by' => 999999, 'deliverable_version_id' => 999999,
            'decision' => 'approved', 'reviewed_at' => '2000-01-01',
        ])->fresh();

        $this->assertSame(DeliverableReviewDecision::ChangesRequested, $review->decision);
        $this->assertSame("Please revise <script>alert(1)</script>.\nKeep the earlier files.", $review->remarks);
        $this->assertSame($version->id, $review->deliverable_version_id);
        $this->assertSame($version->deliverable->assignment->project->client_id, $review->reviewed_by);
        $this->assertSame('changes_requested', $version->deliverable->fresh()->reviewState());
        $this->assertFalse($version->deliverable->fresh()->isApproved());
    }

    #[DataProvider('optionalApprovalRemarks')]
    public function test_approval_accepts_optional_remarks_and_normalizes_empty_values(?string $remarks): void
    {
        $review = $this->review($this->version(), 'approve', ['remarks' => $remarks]);
        $this->assertNull($review->remarks);
    }

    public function test_approval_requires_explicit_confirmation_even_when_called_without_input(): void
    {
        $version = $this->version();
        $this->actingAs($version->deliverable->assignment->project->client);
        try {
            app(ReviewDeliverableVersion::class)->approve($version->deliverable->assignment->project_id, $version->deliverable_id, $version->id);
            $this->fail('Approval requires an explicit confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmed', $exception->errors());
        }
        $this->assertDatabaseCount('deliverable_reviews', 0);
    }

    #[DataProvider('invalidReviewInput')]
    public function test_review_validation_preserves_the_entire_prior_submission(string $method, array $input, string $field): void
    {
        $version = $this->version();
        $before = $version->getAttributes();
        $bytes = Storage::disk('deliverables')->get($version->storage_path);
        try {
            $this->review($version, $method, $input);
            $this->fail('Invalid review input must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('deliverable_reviews', 0);
        $this->assertSame($before, $version->fresh()->getAttributes());
        $this->assertSame($bytes, Storage::disk('deliverables')->get($version->storage_path));
        $this->assertSame('awaiting_review', $version->deliverable->fresh()->reviewState());
    }

    #[DataProvider('reviewMethods')]
    public function test_maximum_length_remarks_are_supported(string $method): void
    {
        $review = $this->review($this->version(), $method, ['remarks' => str_repeat('r', 5000)]);
        $this->assertSame(5000, strlen($review->remarks));
    }

    #[DataProvider('forbiddenActors')]
    public function test_guests_freelancers_admins_and_unrelated_clients_cannot_review(string $actor): void
    {
        $version = $this->version();
        $assignment = $version->deliverable->assignment;
        $user = match ($actor) {
            'guest' => null,
            'assigned_freelancer' => $assignment->freelancer,
            default => User::factory()->create(['role' => $actor]),
        };
        $user ? $this->actingAs($user) : Auth::logout();
        try {
            $this->callReview($version, 'approve', ['confirmed' => true]);
            $this->fail('Only the owning Client may review.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $exception->status() ?? 403);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('deliverable_reviews', 0);
    }

    public function test_persisted_client_role_and_ownership_are_rechecked_after_a_page_was_opened(): void
    {
        $version = $this->version();
        $project = $version->deliverable->assignment->project;
        $client = $project->client;
        $this->actingAs($client);
        DB::table('users')->where('id', $client->id)->update(['role' => User::ROLE_ADMIN]);
        try {
            $this->callReview($version, 'approve', ['confirmed' => true]);
            $this->fail('A persisted role change must deny a stale review form.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('deliverable_reviews', 0);
        }
        DB::table('users')->where('id', $client->id)->update(['role' => User::ROLE_CLIENT]);
        DB::table('projects')->where('id', $project->id)->update(['client_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id]);
        $this->expectException(AuthorizationException::class);
        $this->callReview($version, 'approve', ['confirmed' => true]);
    }

    public function test_deliverables_and_versions_are_resolved_within_the_correct_nested_assignment(): void
    {
        $version = $this->version();
        $other = $this->version();
        $assignment = $version->deliverable->assignment;
        $this->actingAs($assignment->project->client);
        foreach ([[$other->deliverable_id, $other->id], [$version->deliverable_id, $other->id]] as [$deliverableId, $versionId]) {
            try {
                app(ReviewDeliverableVersion::class)->approve($assignment->project_id, $deliverableId, $versionId, ['confirmed' => true]);
                $this->fail('Mismatched nested identifiers must be refused.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('deliverable_reviews', 0);
            }
        }
    }

    #[DataProvider('closedStatuses')]
    public function test_new_reviews_are_refused_when_the_project_is_not_in_progress(ProjectStatus $status): void
    {
        $version = $this->version();
        $version->deliverable->assignment->project->forceFill(['status' => $status])->save();
        try {
            $this->review($version);
            $this->fail('A new review requires an in-progress project.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->assertDatabaseCount('deliverable_reviews', 0);
    }

    public function test_only_the_latest_unreviewed_version_may_receive_a_new_review(): void
    {
        $first = $this->version();
        $second = $this->upload($first->deliverable, 'A newer submission.');
        try {
            $this->review($first);
            $this->fail('The superseded version must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $review = $this->review($second);
        $this->assertSame($second->id, $review->deliverable_version_id);
        $this->assertNull($first->fresh()->review);
        $this->assertDatabaseCount('deliverable_reviews', 1);
    }

    #[DataProvider('reviewMethods')]
    public function test_exact_normalized_retries_preserve_the_original_review_timestamp_and_attributes(string $method): void
    {
        $version = $this->version();
        $first = $this->review($version, $method, ['remarks' => '  Keep the original review.  '])->fresh();
        $before = $first->getAttributes();
        $this->travel(2)->days();
        $again = $this->review($version, $method, ['remarks' => "\nKeep the original review.\t"])->fresh();
        $this->assertSame($before, $again->getAttributes());
        $this->assertDatabaseCount('deliverable_reviews', 1);
    }

    public function test_exact_retry_survives_a_newer_version_and_a_later_project_status_change(): void
    {
        $first = $this->version();
        $review = $this->review($first, 'requestChanges', ['remarks' => 'Revise the introduction.'])->fresh();
        $before = $review->getAttributes();
        $this->upload($first->deliverable);
        $first->deliverable->assignment->project->forceFill(['status' => ProjectStatus::Completed])->save();
        $this->travel(1)->days();
        $retry = $this->review($first, 'requestChanges', ['remarks' => 'Revise the introduction.'])->fresh();
        $this->assertSame($before, $retry->getAttributes());
        $this->assertDatabaseCount('deliverable_reviews', 1);
        $this->assertSame('awaiting_review', $first->deliverable->fresh()->reviewState());
    }

    #[DataProvider('conflictingReviews')]
    public function test_changed_remarks_or_a_second_decision_cannot_overwrite_a_completed_review(string $originalMethod, string $nextMethod, ?string $nextRemarks): void
    {
        $version = $this->version();
        $first = $this->review($version, $originalMethod, ['remarks' => 'Original remarks.'])->fresh();
        $before = $first->getAttributes();
        try {
            $this->review($version, $nextMethod, ['remarks' => $nextRemarks]);
            $this->fail('A completed review must not be replaced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertDatabaseCount('deliverable_reviews', 1);
    }

    #[DataProvider('databaseFailures')]
    public function test_database_failure_or_save_veto_rolls_back_review_and_preserves_files(string $failure): void
    {
        $version = $this->version();
        $before = $version->getAttributes();
        $bytes = Storage::disk('deliverables')->get($version->storage_path);
        DeliverableReview::creating(function () use ($failure) {
            if ($failure === 'veto') {
                return false;
            }
            throw new RuntimeException('Simulated review save failure');
        });
        try {
            $this->review($version);
            $this->fail('A failed review save must not succeed.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('deliverable_reviews', 0);
        }
        $this->assertSame($before, $version->fresh()->getAttributes());
        $this->assertSame($bytes, Storage::disk('deliverables')->get($version->storage_path));
        $this->assertSame('awaiting_review', $version->deliverable->fresh()->reviewState());
    }

    public function test_unrelated_unique_constraint_failure_is_not_hidden_as_an_idempotent_review(): void
    {
        $existing = DeliverableReview::factory()->create();
        $version = $this->version();
        DeliverableReview::creating(function (DeliverableReview $review) use ($existing): void {
            $review->id = $existing->id;
        });
        try {
            $this->review($version);
            $this->fail('An unrelated primary-key collision must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString('deliverable_reviews.id', (string) ($exception->errorInfo[2] ?? ''));
        }
        $this->assertDatabaseCount('deliverable_reviews', 1);
        $this->assertNull($version->fresh()->review);
        Storage::disk('deliverables')->assertExists($version->storage_path);
    }

    #[DataProvider('conflictRecoveryDecisions')]
    public function test_simulated_unique_conflict_recovers_only_an_exact_committed_review(string $decision): void
    {
        // Inject a competing result after rollback; this is not parallel MySQL execution.
        $version = $this->version();
        $reviewedAt = CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d H:i:s');
        DeliverableReview::creating(function () use ($version, $decision, $reviewedAt): void {
            DB::afterRollBack(function () use ($version, $decision, $reviewedAt): void {
                DB::table('deliverable_reviews')->insert([
                    'deliverable_version_id' => $version->id,
                    'reviewed_by' => $version->deliverable->assignment->project->client_id,
                    'decision' => $decision, 'remarks' => 'Original remarks.',
                    'reviewed_at' => $reviewedAt, 'created_at' => $reviewedAt, 'updated_at' => $reviewedAt,
                ]);
            });
            $previous = new \PDOException('Simulated version-review conflict');
            $previous->errorInfo = ['23000', 19, 'UNIQUE constraint failed: deliverable_reviews.deliverable_version_id'];
            throw new UniqueConstraintViolationException('sqlite', 'insert into deliverable_reviews', [], $previous);
        });

        try {
            $review = $this->review($version, 'approve', ['remarks' => 'Original remarks.']);
            $this->assertSame('approved', $decision);
            $this->assertSame($reviewedAt, $review->reviewed_at->format('Y-m-d H:i:s'));
        } catch (ValidationException $exception) {
            $this->assertSame('changes_requested', $decision);
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->assertDatabaseCount('deliverable_reviews', 1);
        $this->assertSame($decision, DeliverableReview::sole()->decision->value);
        Storage::disk('deliverables')->assertExists($version->storage_path);
    }

    public function test_matching_input_from_a_different_persisted_reviewer_is_not_an_exact_retry(): void
    {
        $version = $this->version();
        $review = $this->review($version, 'approve', ['remarks' => 'Original remarks.'])->fresh();
        $before = $review->getAttributes();
        $nextOwner = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $version->deliverable->assignment->project->forceFill(['client_id' => $nextOwner->id])->save();
        $this->actingAs($nextOwner);
        try {
            $this->callReview($version, 'approve', ['confirmed' => true, 'remarks' => 'Original remarks.']);
            $this->fail('A new owner cannot impersonate the original review retry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->assertSame($before, $review->fresh()->getAttributes());
    }

    public function test_failure_after_review_insert_rolls_back_the_insert_and_preserves_the_version(): void
    {
        $version = $this->version();
        $before = $version->getAttributes();
        DeliverableReview::created(function (): void {
            throw new RuntimeException('Simulated failure after review insert');
        });
        try {
            $this->review($version);
            $this->fail('A failure after inserting a review must still roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after review insert', $exception->getMessage());
        }
        $this->assertDatabaseCount('deliverable_reviews', 0);
        $this->assertSame($before, $version->fresh()->getAttributes());
        Storage::disk('deliverables')->assertExists($version->storage_path);
    }

    public function test_outer_transaction_rollback_removes_the_review_without_affecting_submitted_files(): void
    {
        $version = $this->version();
        $this->actingAs($version->deliverable->assignment->project->client);
        try {
            DB::transaction(function () use ($version): void {
                $this->callReview($version, 'approve', ['confirmed' => true]);
                $this->assertDatabaseCount('deliverable_reviews', 1);
                throw new RuntimeException('Abort outer review transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort outer review transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount('deliverable_reviews', 0);
        Storage::disk('deliverables')->assertExists($version->storage_path);
    }

    public function test_revision_upload_after_changes_requested_preserves_previous_files_and_review(): void
    {
        $first = $this->version();
        $review = $this->review($first, 'requestChanges', ['remarks' => 'Please expand the conclusion.'])->fresh();
        $reviewBefore = $review->getAttributes();
        $versionBefore = $first->getAttributes();
        $bytes = Storage::disk('deliverables')->get($first->storage_path);
        $this->travel(1)->hours();
        $second = $this->upload($first->deliverable, 'Expanded conclusion.', 'Expanded the conclusion as requested.');

        $this->assertSame(2, $second->version_number);
        $this->assertSame('Expanded the conclusion as requested.', $second->submission_note);
        $this->assertSame($reviewBefore, $review->fresh()->getAttributes());
        $this->assertSame($versionBefore, $first->fresh()->getAttributes());
        $this->assertSame($bytes, Storage::disk('deliverables')->get($first->storage_path));
        $this->assertSame('awaiting_review', $first->deliverable->fresh()->reviewState());
        $this->assertNull($second->review);
        $this->assertCount(2, Storage::disk('deliverables')->allFiles());
    }

    public function test_approved_deliverable_refuses_new_uploads_but_existing_token_retry_and_other_deliverables_work(): void
    {
        $first = $this->version();
        $assignment = $first->deliverable->assignment;
        $this->review($first);
        $this->actingAs($assignment->freelancer);
        try {
            // No file: approval must be checked before file preparation or storage.
            app(SubmitDeliverable::class)->submit($assignment->project_id, $first->deliverable_id, [], null, (string) Str::uuid());
            $this->fail('A stale upload form must not append to an approved deliverable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deliverables', $exception->errors());
            $this->assertArrayNotHasKey('file', $exception->errors());
        }
        $retry = app(SubmitDeliverable::class)->submit($assignment->project_id, $first->deliverable_id, [], null, $first->submission_id);
        $this->assertTrue($retry->is($first));
        $other = app(SubmitDeliverable::class)->submit($assignment->project_id, null, ['title' => 'Separate handover'], $this->file('Other deliverable.'), (string) Str::uuid());
        $this->assertNotSame($first->deliverable_id, $other->deliverable_id);
        $this->assertDatabaseCount('deliverable_versions', 2);
        $this->assertDatabaseCount('deliverable_reviews', 1);
        $this->assertCount(2, Storage::disk('deliverables')->allFiles());
    }

    public function test_simulated_upload_then_stale_approval_cannot_approve_an_outdated_version(): void
    {
        // Sequential interleaving exercises persisted-state checks; this is not parallel MySQL testing.
        $first = $this->version();
        $second = $this->upload($first->deliverable, 'New work while the Client has the old page open.');
        try {
            $this->review($first);
            $this->fail('The old Client form must be refused after a newer upload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->assertDatabaseCount('deliverable_reviews', 0);
        $this->assertTrue($first->deliverable->fresh()->latestVersion->is($second));
        $this->assertCount(2, Storage::disk('deliverables')->allFiles());
    }

    public function test_simulated_approval_then_stale_upload_preserves_original_approval_and_files(): void
    {
        // Reverse sequential interleaving: approval commits before the stale upload mutation.
        $first = $this->version();
        $review = $this->review($first)->fresh();
        $before = $review->getAttributes();
        try {
            $this->upload($first->deliverable);
            $this->fail('An upload opened before approval must be refused after approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deliverables', $exception->errors());
        }
        $this->assertSame($before, $review->fresh()->getAttributes());
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$first->storage_path], Storage::disk('deliverables')->allFiles());
    }

    private function version(): DeliverableVersion
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->freelancer);

        return app(SubmitDeliverable::class)->submit($assignment->project_id, null, ['title' => 'First handover'], $this->file(), (string) Str::uuid())->fresh();
    }

    private function upload(Deliverable $deliverable, string $bytes = 'Revised private work.', ?string $note = null): DeliverableVersion
    {
        $this->actingAs($deliverable->assignment->freelancer);

        return app(SubmitDeliverable::class)->submit($deliverable->assignment->project_id, $deliverable->id, ['submission_note' => $note], $this->file($bytes), (string) Str::uuid())->fresh();
    }

    private function review(DeliverableVersion $version, string $method = 'approve', array $input = []): DeliverableReview
    {
        $this->actingAs($version->deliverable->assignment->project->client);

        return $this->callReview($version, $method, array_replace(['confirmed' => true], $input));
    }

    private function callReview(DeliverableVersion $version, string $method, array $input): DeliverableReview
    {
        return app(ReviewDeliverableVersion::class)->{$method}($version->deliverable->assignment->project_id, $version->deliverable_id, $version->id, $input);
    }

    private function file(string $bytes = 'Original private work.'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('handover.txt', $bytes);
    }

    public static function optionalApprovalRemarks(): array
    {
        return [[null], [''], [" \n\t "], ["\u{00A0}\u{2003}"]];
    }

    public static function invalidReviewInput(): array
    {
        return [
            ['approve', ['confirmed' => false], 'confirmed'],
            ['approve', ['confirmed' => null], 'confirmed'],
            ['approve', ['confirmed' => 'no'], 'confirmed'],
            ['approve', ['confirmed' => []], 'confirmed'],
            ['approve', ['remarks' => []], 'remarks'],
            ['approve', ['remarks' => str_repeat('r', 5001)], 'remarks'],
            ['requestChanges', [], 'remarks'],
            ['requestChanges', ['remarks' => null], 'remarks'],
            ['requestChanges', ['remarks' => ''], 'remarks'],
            ['requestChanges', ['remarks' => " \n\t "], 'remarks'],
            ['requestChanges', ['remarks' => "\u{00A0}\u{2003}"], 'remarks'],
            ['requestChanges', ['remarks' => []], 'remarks'],
            ['requestChanges', ['remarks' => str_repeat('r', 5001)], 'remarks'],
        ];
    }

    public static function reviewMethods(): array
    {
        return [['approve'], ['requestChanges']];
    }

    public static function forbiddenActors(): array
    {
        return [['guest'], ['assigned_freelancer'], [User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function closedStatuses(): array
    {
        return array_map(fn ($status) => [$status], [ProjectStatus::Draft, ProjectStatus::Open, ProjectStatus::Completed, ProjectStatus::Cancelled]);
    }

    public static function conflictingReviews(): array
    {
        return [
            ['approve', 'requestChanges', 'Original remarks.'],
            ['requestChanges', 'approve', 'Original remarks.'],
            ['approve', 'approve', 'Changed remarks.'],
            ['requestChanges', 'requestChanges', 'Changed remarks.'],
            ['approve', 'approve', null],
        ];
    }

    public static function databaseFailures(): array
    {
        return [['veto'], ['exception']];
    }

    public static function conflictRecoveryDecisions(): array
    {
        return [['approved'], ['changes_requested']];
    }
}
