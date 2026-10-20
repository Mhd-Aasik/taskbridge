<?php

namespace Tests\Feature;

use App\Actions\Deliverables\SubmitDeliverable;
use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Milestone;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Services\DeliverableFiles;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeliverableSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'));
        Storage::fake('deliverables');
    }

    public function test_submission_creates_server_owned_immutable_metadata_and_exact_original_file(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $token = (string) Str::uuid();
        $file = $this->file();
        $bytes = file_get_contents($file->getRealPath());
        $before = $assignment->project->fresh()->getAttributes();
        $version = $this->submit($assignment, null, [
            'title' => '  First handover  ', 'description' => "  Two lines\nPlain text  ", 'milestone_id' => $milestone->id,
            'submission_note' => '  Ready for review  ', 'project_assignment_id' => 999999, 'created_by' => 999999,
            'uploaded_by' => 999999, 'deliverable_id' => 999999, 'version_number' => 99,
            'storage_path' => '../../public/forged.txt', 'mime_type' => 'text/html', 'byte_size' => 1,
            'sha256' => str_repeat('0', 64), 'submitted_at' => '2000-01-01', 'submission_id' => (string) Str::uuid(),
            'role' => 'admin', 'is_public' => true,
        ], $file, $token)->fresh();
        $deliverable = $version->deliverable;
        $this->assertSame('First handover', $deliverable->title);
        $this->assertSame("Two lines\nPlain text", $deliverable->description);
        $this->assertSame($assignment->id, $deliverable->project_assignment_id);
        $this->assertSame($assignment->freelancer_id, $deliverable->created_by);
        $this->assertSame($milestone->id, $deliverable->milestone_id);
        $this->assertSame($assignment->freelancer_id, $version->uploaded_by);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('Ready for review', $version->submission_note);
        $this->assertSame($token, $version->submission_id);
        $this->assertSame('2026-10-24 12:00:00', $version->submitted_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue(DeliverableFiles::ownsPath($version));
        $this->assertSame('text/plain', $version->mime_type);
        $this->assertSame(strlen($bytes), $version->byte_size);
        $this->assertSame(hash('sha256', $bytes), $version->sha256);
        $this->assertSame($bytes, Storage::disk('deliverables')->get($version->storage_path));
        $this->assertArrayNotHasKey('storage_path', $version->toArray());
        $this->assertArrayNotHasKey('submission_id', $version->toArray());
        $this->assertSame($before, $assignment->project->fresh()->getAttributes());
        $this->assertSame(User::ROLE_FREELANCER, $assignment->freelancer->fresh()->role);
    }

    public function test_successive_versions_are_ordered_and_do_not_replace_previous_bytes_or_deliverable_details(): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment)->fresh();
        $original = $first->getAttributes();
        $deliverable = $first->deliverable;
        $details = $deliverable->getAttributes();
        $firstBytes = Storage::disk('deliverables')->get($first->storage_path);
        $this->travel(1)->hours();
        $second = $this->submit($assignment, $deliverable->id, [
            'title' => 'Forged replacement title', 'description' => 'Forged replacement description',
            'milestone_id' => 999999, 'submission_note' => 'Second version',
        ], $this->file('Second file contents.'))->fresh();
        $this->assertSame(2, $second->version_number);
        $this->assertNotSame($first->storage_path, $second->storage_path);
        $this->assertSame([$second->id, $first->id], $deliverable->versions->modelKeys());
        $this->assertTrue($deliverable->fresh()->latestVersion->is($second));
        $this->assertSame($original, $first->fresh()->getAttributes());
        $this->assertSame($details, $deliverable->fresh()->getAttributes());
        $this->assertSame($firstBytes, Storage::disk('deliverables')->get($first->storage_path));
        $this->assertCount(2, Storage::disk('deliverables')->allFiles());
    }

    public function test_stable_submission_identifier_retries_ignore_replacement_fields_and_preserve_original_timestamp(): void
    {
        $assignment = $this->assignment();
        $token = (string) Str::uuid();
        $first = $this->submit($assignment, null, [], null, $token)->fresh();
        $before = $first->getAttributes();
        $this->travel(2)->days();
        // No file is needed to recover a previously committed submission.
        $again = app(SubmitDeliverable::class)->submit($assignment->project_id, null, ['title' => 'Overwrite?', 'submission_note' => 'Overwrite?'], null, $token)->fresh();
        $this->assertSame($before, $again->getAttributes());
        $this->assertDatabaseCount('deliverables', 1);
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$first->storage_path], Storage::disk('deliverables')->allFiles());
        $secondToken = (string) Str::uuid();
        $second = $this->submit($assignment, $first->deliverable_id, [], null, $secondToken)->fresh();
        $retry = app(SubmitDeliverable::class)->submit($assignment->project_id, $first->deliverable_id, [], null, $secondToken)->fresh();
        $this->assertSame($second->getAttributes(), $retry->getAttributes());
        $this->assertDatabaseCount('deliverable_versions', 2);
    }

    public function test_committed_retry_remains_readable_after_project_finishes_without_allowing_new_uploads(): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment)->fresh();
        $assignment->project->forceFill(['status' => ProjectStatus::Completed])->save();
        $retry = app(SubmitDeliverable::class)->submit($assignment->project_id, null, [], null, $first->submission_id);
        $this->assertTrue($retry->is($first));
        try {
            $this->submit($assignment, $first->deliverable_id);
            $this->fail('A new version must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deliverables', $exception->errors());
        }
        $this->assertDatabaseCount('deliverable_versions', 1);
    }

    #[DataProvider('closedStatuses')]
    public function test_non_in_progress_projects_refuse_new_deliverables(ProjectStatus $status): void
    {
        $assignment = $this->assignment();
        $assignment->project->forceFill(['status' => $status])->save();
        $this->expectException(ValidationException::class);
        $this->submit($assignment);
    }

    public function test_guests_and_the_owning_client_cannot_upload(): void
    {
        $assignment = $this->assignment();
        foreach ([null, $assignment->project->client] as $actor) {
            $actor ? $this->actingAs($actor) : Auth::logout();
            try {
                app(SubmitDeliverable::class)->submit($assignment->project_id, null, $this->details(), $this->file(), (string) Str::uuid());
                $this->fail('Only an assigned Freelancer may upload.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('deliverables', 0);
        $this->assertSame([], Storage::disk('deliverables')->allFiles());
    }

    #[DataProvider('unrelatedRoles')]
    public function test_other_accounts_and_roles_cannot_upload(string $role): void
    {
        $assignment = $this->assignment();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->expectException(AuthorizationException::class);
        app(SubmitDeliverable::class)->submit($assignment->project_id, null, $this->details(), $this->file(), (string) Str::uuid());
    }

    public function test_persisted_role_and_assignment_changes_refuse_stale_uploads(): void
    {
        $assignment = $this->assignment();
        DB::table('users')->where('id', $assignment->freelancer_id)->update(['role' => User::ROLE_ADMIN]);
        try {
            app(SubmitDeliverable::class)->submit($assignment->project_id, null, $this->details(), $this->file(), (string) Str::uuid());
            $this->fail('A persisted role change must be checked.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('deliverable_versions', 0);
        }
        DB::table('users')->where('id', $assignment->freelancer_id)->update(['role' => User::ROLE_FREELANCER]);
        DB::table('project_assignments')->where('id', $assignment->id)->update(['freelancer_id' => User::factory()->create(['role' => User::ROLE_FREELANCER])->id]);
        $this->expectException(AuthorizationException::class);
        app(SubmitDeliverable::class)->submit($assignment->project_id, null, $this->details(), $this->file(), (string) Str::uuid());
    }

    public function test_milestones_and_deliverable_ids_are_scoped_to_the_assignment(): void
    {
        $assignment = $this->assignment();
        $other = Deliverable::factory()->create();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $other->project_assignment_id]);
        try {
            $this->submit($assignment, null, ['milestone_id' => $milestone->id]);
            $this->fail('A foreign milestone must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('milestone_id', $exception->errors());
        }
        $this->expectException(ModelNotFoundException::class);
        $this->submit($assignment, $other->id);
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_details_leave_no_new_records_or_files(array $override, string $field): void
    {
        $assignment = $this->assignment();
        try {
            $this->submit($assignment, null, $override);
            $this->fail('Invalid deliverable details must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('deliverables', 0);
        $this->assertDatabaseCount('deliverable_versions', 0);
        $this->assertSame([], Storage::disk('deliverables')->allFiles());
    }

    public function test_valid_text_boundaries_and_optional_empty_fields_are_supported(): void
    {
        $assignment = $this->assignment();
        $version = $this->submit($assignment, null, ['title' => str_repeat('t', 150), 'description' => str_repeat('d', 5000), 'submission_note' => str_repeat('n', 5000)]);
        $this->assertSame(5000, strlen($version->submission_note));
        $this->assertSame(5000, strlen($version->deliverable->description));
        $blank = $this->submit($assignment, null, ['description' => " \n ", 'submission_note' => " \n ", 'milestone_id' => '']);
        $this->assertNull($blank->submission_note);
        $this->assertNull($blank->deliverable->description);
        $this->assertNull($blank->deliverable->milestone_id);
    }

    public function test_missing_or_invalid_file_is_atomic_and_preserves_an_existing_version(): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment)->fresh();
        foreach ([null, UploadedFile::fake()->createWithContent('not-a-png.png', 'not a PNG')] as $file) {
            try {
                app(SubmitDeliverable::class)->submit($assignment->project_id, $first->deliverable_id, [], $file, (string) Str::uuid());
                $this->fail('Missing/invalid file must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$first->storage_path], Storage::disk('deliverables')->allFiles());
    }

    public function test_reusing_a_token_for_another_project_cannot_return_private_history_or_leave_orphans(): void
    {
        $assignment = $this->assignment();
        $other = ProjectAssignment::factory()->create(['freelancer_id' => $assignment->freelancer_id]);
        $existing = $this->submit($other)->fresh();
        $before = $existing->getAttributes();
        try {
            $this->submit($assignment, null, [], null, $existing->submission_id);
            $this->fail('A token from another project must not be reused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deliverables', $exception->errors());
        }
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('deliverables', 1);
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$existing->storage_path], Storage::disk('deliverables')->allFiles());
    }

    public function test_reusing_a_token_for_another_deliverable_is_refused(): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment);
        $other = $this->submit($assignment);
        $this->expectException(ValidationException::class);
        $this->submit($assignment, $other->deliverable_id, [], null, $first->submission_id);
    }

    public function test_unrelated_unique_constraint_errors_are_not_swallowed_and_previous_files_survive(): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment);
        DeliverableVersion::creating(function (DeliverableVersion $version): void {
            $version->version_number = 1;
        });
        try {
            $this->submit($assignment, $first->deliverable_id);
            $this->fail('A version-number uniqueness failure must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringNotContainsString('submission_id', (string) ($exception->errorInfo[2] ?? ''));
        }
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$first->storage_path], Storage::disk('deliverables')->allFiles());
    }

    #[DataProvider('databaseFailures')]
    public function test_failed_database_saves_clean_new_files_and_preserve_previous_versions(string $failure): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment)->fresh();
        $before = $first->getAttributes();
        DeliverableVersion::creating(function () use ($failure) {
            if ($failure === 'veto') {
                return false;
            }
            throw new RuntimeException('Simulated version save failure');
        });
        try {
            $this->submit($assignment, $first->deliverable_id);
            $this->fail('A failed save must not succeed.');
        } catch (RuntimeException) {
            $this->assertSame($before, $first->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$first->storage_path], Storage::disk('deliverables')->allFiles());
    }

    public function test_failed_initial_creation_cleans_records_and_files(): void
    {
        $assignment = $this->assignment();
        Deliverable::creating(fn () => false);
        try {
            $this->submit($assignment);
            $this->fail('Vetoed creation must fail.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('deliverables', 0);
            $this->assertSame([], Storage::disk('deliverables')->allFiles());
        }
    }

    public function test_partial_storage_failure_cleans_up_only_the_new_upload(): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment);
        $disk = Storage::disk('deliverables');
        $failed = Mockery::mock(FilesystemAdapter::class);
        $failed->shouldReceive('exists')->andReturn(false);
        $failed->shouldReceive('put')->once()->andReturnUsing(function ($path) use ($disk): void {
            $disk->put($path, 'partial upload');
            throw new RuntimeException('Simulated storage failure');
        });
        $failed->shouldReceive('delete')->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('deliverables')->andReturn($failed);
        try {
            $this->submit($assignment, $first->deliverable_id);
            $this->fail('Storage failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated storage failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$first->storage_path], $disk->allFiles());
    }

    public function test_outer_rollback_removes_new_file_without_affecting_previous_versions(): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment);
        try {
            DB::transaction(function () use ($assignment, $first): void {
                $new = $this->submit($assignment, $first->deliverable_id);
                Storage::disk('deliverables')->assertExists($new->storage_path);
                throw new RuntimeException('Abort outer transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort outer transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$first->storage_path], Storage::disk('deliverables')->allFiles());
    }

    public function test_an_unexpected_generated_filename_collision_never_overwrites_an_earlier_file(): void
    {
        $assignment = $this->assignment();
        $first = $this->submit($assignment);
        $original = Storage::disk('deliverables')->get($first->storage_path);
        $nextToken = (string) Str::uuid();
        $collision = Uuid::fromString(pathinfo($first->storage_path, PATHINFO_FILENAME));
        Str::createUuidsUsing(fn () => $collision);
        try {
            $this->submit($assignment, $first->deliverable_id, [], $this->file('New bytes must not overwrite.'), $nextToken);
            $this->fail('The collision must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unique upload filename', $exception->getMessage());
        } finally {
            Str::createUuidsNormally();
        }
        $this->assertSame($original, Storage::disk('deliverables')->get($first->storage_path));
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame([$first->storage_path], Storage::disk('deliverables')->allFiles());
    }

    public function test_post_commit_response_failure_preserves_the_durable_version_and_retry_recovers_it(): void
    {
        $assignment = $this->assignment();
        $token = (string) Str::uuid();
        DeliverableVersion::created(function (): void {
            DB::afterCommit(function (): void {
                throw new RuntimeException('Simulated post-commit response failure');
            });
        });
        try {
            $this->submit($assignment, null, [], null, $token);
            $this->fail('The simulated response failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated post-commit response failure', $exception->getMessage());
        }
        $saved = DeliverableVersion::sole();
        Storage::disk('deliverables')->assertExists($saved->storage_path);
        $retry = app(SubmitDeliverable::class)->submit($assignment->project_id, null, [], null, $token);
        $this->assertTrue($retry->is($saved));
        $this->assertSame([$saved->storage_path], Storage::disk('deliverables')->allFiles());
        $this->assertDatabaseCount('deliverables', 1);
        $this->assertDatabaseCount('deliverable_versions', 1);
    }

    private function assignment(): ProjectAssignment
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->freelancer);

        return $assignment;
    }

    private function submit(ProjectAssignment $assignment, ?int $deliverableId = null, array $input = [], ?UploadedFile $file = null, ?string $token = null): DeliverableVersion
    {
        return app(SubmitDeliverable::class)->submit($assignment->project_id, $deliverableId, array_replace($this->details(), $input), $file ?? $this->file(), $token ?? (string) Str::uuid());
    }

    private function details(): array
    {
        return ['title' => 'First handover', 'description' => 'Private work in plain text.', 'milestone_id' => null, 'submission_note' => null];
    }

    private function file(string $bytes = 'Original deliverable contents.'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('handover.txt', $bytes);
    }

    public static function closedStatuses(): array
    {
        return array_map(fn ($status) => [$status], [ProjectStatus::Draft, ProjectStatus::Open, ProjectStatus::Completed, ProjectStatus::Cancelled]);
    }

    public static function unrelatedRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function invalidDetails(): array
    {
        return [[['title' => ''], 'title'], [['title' => " \n\t "], 'title'], [['title' => "\u{00A0}\u{2003}"], 'title'],
            [['title' => str_repeat('t', 151)], 'title'], [['description' => str_repeat('d', 5001)], 'description'],
            [['submission_note' => str_repeat('n', 5001)], 'submission_note'], [['submission_note' => []], 'submission_note'],
            [['milestone_id' => -1], 'milestone_id'], [['milestone_id' => 'not-an-id'], 'milestone_id'], [['milestone_id' => 999999], 'milestone_id']];
    }

    public static function databaseFailures(): array
    {
        return [['veto'], ['exception']];
    }
}
