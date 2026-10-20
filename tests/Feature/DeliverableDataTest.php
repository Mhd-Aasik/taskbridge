<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Milestone;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Policies\DeliverablePolicy;
use App\Policies\DeliverableVersionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliverableDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_deliverable_factory_and_typed_relationships(): void
    {
        $deliverable = Deliverable::factory()->create(['description' => null])->fresh();

        $this->assertTrue($deliverable->assignment->deliverables->sole()->is($deliverable));
        $this->assertTrue($deliverable->creator->createdDeliverables->sole()->is($deliverable));
        $this->assertSame($deliverable->assignment->freelancer_id, $deliverable->created_by);
        $this->assertNull($deliverable->milestone);
        $this->assertNull($deliverable->description);
        $this->assertNull($deliverable->latestVersion);
        $this->assertCount(0, $deliverable->versions);
        $this->assertNotNull($deliverable->created_at);
        $this->assertNotNull($deliverable->updated_at);
    }

    public function test_milestone_deliverables_belong_to_the_same_assignment(): void
    {
        $milestone = Milestone::factory()->create();
        $deliverable = Deliverable::factory()->create(['milestone_id' => $milestone->id]);

        $this->assertTrue($deliverable->milestone->is($milestone));
        $this->assertTrue($milestone->deliverables->sole()->is($deliverable));
        $this->assertTrue($deliverable->assignment->is($milestone->assignment));
    }

    public function test_versions_are_numbered_and_ordered_and_latest_can_be_eager_loaded(): void
    {
        $deliverable = Deliverable::factory()->create();
        $first = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $second = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $third = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $deliverable = Deliverable::with(['versions', 'latestVersion'])->findOrFail($deliverable->id);

        $this->assertSame([3, 2, 1], $deliverable->versions->pluck('version_number')->all());
        $this->assertSame([$third->id, $second->id, $first->id], $deliverable->versions->modelKeys());
        $this->assertTrue($deliverable->latestVersion->is($third));
        $this->assertTrue($first->deliverable->is($deliverable));
        $this->assertSame($deliverable->assignment->freelancer_id, $first->uploaded_by);
        $this->assertSame([$first->id, $second->id, $third->id], $first->uploader->uploadedDeliverableVersions->modelKeys());
    }

    public function test_version_metadata_casts_and_submission_timestamp_normalize_to_utc(): void
    {
        $version = DeliverableVersion::factory()->create([
            'byte_size' => '10485760',
            'submission_note' => null,
            'submitted_at' => CarbonImmutable::parse('2026-10-24 17:30:00', 'Asia/Colombo'),
        ])->fresh();

        $this->assertSame(1, $version->version_number);
        $this->assertSame(10485760, $version->byte_size);
        $this->assertNull($version->submission_note);
        $this->assertInstanceOf(CarbonImmutable::class, $version->submitted_at);
        $this->assertSame('2026-10-24 12:00:00', $version->getRawOriginal('submitted_at'));
        $this->assertSame('UTC', $version->submitted_at->timezoneName);
        $this->assertSame(64, strlen($version->sha256));
        $this->assertSame(36, strlen($version->submission_id));
        $this->assertArrayNotHasKey('storage_path', $version->toArray());
        $this->assertArrayNotHasKey('submission_id', $version->toArray());
    }

    public function test_deliverable_mass_assignment_allows_only_title_and_description(): void
    {
        $deliverable = Deliverable::factory()->create()->fresh();
        $before = $deliverable->getAttributes();
        $guarded = [
            'id' => 999999, 'project_assignment_id' => 999999, 'milestone_id' => 999999,
            'created_by' => 999999, 'created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-01 00:00:00',
        ];
        $deliverable->update($guarded + ['title' => 'Updated brief', 'description' => 'Plain text.']);

        $this->assertSame('Updated brief', $deliverable->fresh()->title);
        $this->assertSame('Plain text.', $deliverable->fresh()->description);
        foreach (array_keys($guarded) as $field) {
            $this->assertFalse($deliverable->isFillable($field));
            if ($field !== 'updated_at') {
                $this->assertSame($before[$field] ?? null, $deliverable->fresh()->getRawOriginal($field));
            }
        }
    }

    public function test_every_version_field_is_protected_from_public_mass_assignment(): void
    {
        $version = DeliverableVersion::factory()->create();
        foreach (array_keys($version->getAttributes()) as $field) {
            $this->assertFalse($version->isFillable($field));
        }
        $this->expectException(MassAssignmentException::class);
        new DeliverableVersion(['submission_note' => 'Forged change']);
    }

    public function test_persisted_versions_cannot_be_updated_even_through_force_fill(): void
    {
        $version = DeliverableVersion::factory()->create()->fresh();
        $before = $version->getAttributes();
        try {
            $version->forceFill(['original_filename' => 'replacement.txt', 'storage_path' => 'elsewhere.txt'])->save();
            $this->fail('Submitted versions must be immutable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be changed', $exception->getMessage());
        }
        $this->assertSame($before, $version->fresh()->getAttributes());
    }

    public function test_persisted_versions_cannot_be_deleted(): void
    {
        $version = DeliverableVersion::factory()->create();
        try {
            $version->delete();
            $this->fail('Submitted versions must be preserved.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('must be preserved', $exception->getMessage());
        }
        $this->assertModelExists($version);
    }

    #[DataProvider('deliverableForeignKeys')]
    public function test_deliverable_unknown_foreign_keys_are_rejected(string $field): void
    {
        $values = Deliverable::factory()->make()->getAttributes();
        $values[$field] = 999999;
        $this->expectException(QueryException::class);
        DB::table('deliverables')->insert($values);
    }

    #[DataProvider('versionForeignKeys')]
    public function test_version_unknown_foreign_keys_are_rejected(string $field): void
    {
        $values = DeliverableVersion::factory()->make()->getAttributes();
        $values[$field] = 999999;
        $this->expectException(QueryException::class);
        DB::table('deliverable_versions')->insert($values);
    }

    public function test_database_rejects_a_deliverable_milestone_from_another_assignment(): void
    {
        $milestone = Milestone::factory()->create();
        $assignment = ProjectAssignment::factory()->create();
        $this->expectException(QueryException::class);
        Deliverable::factory()->create(['project_assignment_id' => $assignment->id, 'milestone_id' => $milestone->id]);
    }

    public function test_database_also_rejects_cross_assignment_membership_updates(): void
    {
        $deliverable = Deliverable::factory()->create();
        $otherMilestone = Milestone::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('deliverables')->where('id', $deliverable->id)->update(['milestone_id' => $otherMilestone->id]);
    }

    public function test_referenced_milestone_cannot_be_moved_to_another_assignment(): void
    {
        $milestone = Milestone::factory()->create();
        Deliverable::factory()->create(['milestone_id' => $milestone->id]);
        $otherAssignment = ProjectAssignment::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('milestones')->where('id', $milestone->id)->update(['project_assignment_id' => $otherAssignment->id]);
    }

    #[DataProvider('deliverableParents')]
    public function test_deliverable_history_prevents_parent_deletion(string $parent): void
    {
        $milestone = Milestone::factory()->create();
        $creator = User::factory()->create();
        $deliverable = Deliverable::factory()->create(['milestone_id' => $milestone->id, 'created_by' => $creator->id]);
        $this->expectException(QueryException::class);
        $deliverable->$parent->delete();
    }

    public function test_ungrouped_deliverable_still_restricts_assignment_deletion(): void
    {
        $deliverable = Deliverable::factory()->create();
        $this->expectException(QueryException::class);
        $deliverable->assignment->delete();
    }

    #[DataProvider('versionParents')]
    public function test_version_history_prevents_parent_deletion(string $parent): void
    {
        $uploader = User::factory()->create();
        $version = DeliverableVersion::factory()->create(['uploaded_by' => $uploader->id]);
        $this->expectException(QueryException::class);
        $version->$parent->delete();
    }

    public function test_version_number_is_unique_within_each_deliverable(): void
    {
        $first = DeliverableVersion::factory()->create();
        $this->expectException(QueryException::class);
        DeliverableVersion::factory()->create(['deliverable_id' => $first->deliverable_id, 'version_number' => $first->version_number]);
    }

    public function test_version_numbers_can_repeat_for_different_deliverables(): void
    {
        $first = DeliverableVersion::factory()->create(['version_number' => 1]);
        $other = DeliverableVersion::factory()->create(['version_number' => 1]);
        $this->assertNotSame($first->deliverable_id, $other->deliverable_id);
        $this->assertSame(1, $first->version_number);
        $this->assertSame(1, $other->version_number);
    }

    public function test_submission_identifier_is_globally_unique(): void
    {
        $first = DeliverableVersion::factory()->create();
        $this->expectException(QueryException::class);
        DeliverableVersion::factory()->create(['submission_id' => $first->submission_id]);
    }

    public function test_storage_path_cannot_be_shared_by_different_versions(): void
    {
        $first = DeliverableVersion::factory()->create();
        $this->expectException(QueryException::class);
        DeliverableVersion::factory()->create(['storage_path' => $first->storage_path]);
    }

    public function test_policies_allow_only_current_participants_and_assigned_freelancer_mutation(): void
    {
        $deliverable = Deliverable::factory()->create();
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $assignment = $deliverable->assignment;
        $project = $assignment->project;
        $client = $project->client;
        $freelancer = $assignment->freelancer;
        $policy = app(DeliverablePolicy::class);
        $versionPolicy = app(DeliverableVersionPolicy::class);

        foreach ([$client, $freelancer] as $participant) {
            $this->assertTrue($policy->viewAny($participant, $assignment, $project));
            $this->assertTrue($policy->view($participant, $deliverable, $assignment, $project));
            $this->assertTrue($versionPolicy->download($participant, $version, $deliverable, $assignment, $project));
        }
        $this->assertTrue($policy->create($freelancer, $assignment, $project));
        $this->assertTrue($policy->uploadVersion($freelancer, $deliverable, $assignment, $project));
        $this->assertFalse($policy->create($client, $assignment, $project));
        $this->assertFalse($policy->uploadVersion($client, $deliverable, $assignment, $project));
        $this->assertTrue(Gate::forUser($freelancer)->allows('create', [Deliverable::class, $assignment, $project]));
        $this->assertTrue(Gate::forUser($client)->allows('download', [$version, $deliverable, $assignment, $project]));

        foreach ([User::ROLE_CLIENT, User::ROLE_FREELANCER, User::ROLE_ADMIN] as $role) {
            $outsider = User::factory()->create(['role' => $role]);
            $this->assertFalse($policy->viewAny($outsider, $assignment, $project));
            $this->assertFalse($policy->view($outsider, $deliverable, $assignment, $project));
            $this->assertFalse($policy->create($outsider, $assignment, $project));
            $this->assertFalse($versionPolicy->view($outsider, $version, $deliverable, $assignment, $project));
        }
        $client->forceFill(['role' => User::ROLE_ADMIN]);
        $freelancer->forceFill(['role' => User::ROLE_CLIENT]);
        $this->assertFalse($policy->view($client, $deliverable, $assignment, $project));
        $this->assertFalse($policy->view($freelancer, $deliverable, $assignment, $project));
        $this->assertFalse($policy->create($freelancer, $assignment, $project));
    }

    public function test_policies_reject_mismatched_nested_records(): void
    {
        $version = DeliverableVersion::factory()->create();
        $deliverable = $version->deliverable;
        $assignment = $deliverable->assignment;
        $project = $assignment->project;
        $freelancer = $assignment->freelancer;
        $other = Deliverable::factory()->create();
        $otherVersion = DeliverableVersion::factory()->create(['deliverable_id' => $other->id]);
        $policy = app(DeliverablePolicy::class);
        $versionPolicy = app(DeliverableVersionPolicy::class);

        $this->assertFalse($policy->view($freelancer, $other, $assignment, $project));
        $this->assertFalse($policy->uploadVersion($freelancer, $other, $assignment, $project));
        $this->assertFalse($policy->viewAny($freelancer, $assignment, $other->assignment->project));
        $this->assertFalse($versionPolicy->download($freelancer, $otherVersion, $deliverable, $assignment, $project));
        $this->assertFalse($versionPolicy->download($freelancer, $version, $other, $assignment, $project));
    }

    #[DataProvider('historicalStatuses')]
    public function test_history_remains_readable_but_mutation_is_denied_outside_in_progress(ProjectStatus $status): void
    {
        $version = DeliverableVersion::factory()->create();
        $deliverable = $version->deliverable;
        $assignment = $deliverable->assignment;
        $project = $assignment->project;
        $project->forceFill(['status' => $status])->save();
        $policy = app(DeliverablePolicy::class);
        $versionPolicy = app(DeliverableVersionPolicy::class);

        foreach ([$project->client, $assignment->freelancer] as $participant) {
            $this->assertTrue($policy->view($participant, $deliverable, $assignment, $project));
            $this->assertTrue($versionPolicy->download($participant, $version, $deliverable, $assignment, $project));
            $this->assertFalse($policy->create($participant, $assignment, $project));
            $this->assertFalse($policy->uploadVersion($participant, $deliverable, $assignment, $project));
        }
    }

    public static function deliverableForeignKeys(): array
    {
        return [['project_assignment_id'], ['milestone_id'], ['created_by']];
    }

    public static function versionForeignKeys(): array
    {
        return [['deliverable_id'], ['uploaded_by']];
    }

    public static function deliverableParents(): array
    {
        return [['assignment'], ['milestone'], ['creator']];
    }

    public static function versionParents(): array
    {
        return [['deliverable'], ['uploader']];
    }

    public static function historicalStatuses(): array
    {
        return [[ProjectStatus::Draft], [ProjectStatus::Open], [ProjectStatus::Completed], [ProjectStatus::Cancelled]];
    }
}
