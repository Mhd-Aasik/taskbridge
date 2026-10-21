<?php

namespace Tests\Feature;

use App\Enums\DeliverableReviewDecision;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliverableReviewDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_an_approval_by_the_versions_owning_client_and_typed_relationships(): void
    {
        $review = DeliverableReview::factory()->create()->fresh();
        $version = $review->version;
        $client = $version->deliverable->assignment->project->client;

        $this->assertTrue($version->review->is($review));
        $this->assertTrue($review->reviewer->is($client));
        $this->assertTrue($client->completedDeliverableReviews->sole()->is($review));
        $this->assertSame($version->id, $review->deliverable_version_id);
        $this->assertSame($client->id, $review->reviewed_by);
        $this->assertSame(DeliverableReviewDecision::Approved, $review->decision);
        $this->assertNull($review->remarks);
        $this->assertInstanceOf(CarbonImmutable::class, $review->reviewed_at);
        $this->assertNotNull($review->created_at);
        $this->assertNotNull($review->updated_at);
    }

    public function test_factory_changes_requested_state_and_decision_labels(): void
    {
        $review = DeliverableReview::factory()->changesRequested()->create()->fresh();

        $this->assertSame(DeliverableReviewDecision::ChangesRequested, $review->decision);
        $this->assertSame('changes_requested', $review->getRawOriginal('decision'));
        $this->assertNotSame('', trim($review->remarks));
        $this->assertSame('Changes requested', $review->decision->label());
        $this->assertSame('Approved', DeliverableReviewDecision::Approved->label());
    }

    public function test_existing_version_factory_uses_that_versions_client_as_reviewer(): void
    {
        $version = DeliverableVersion::factory()->create();
        $review = DeliverableReview::factory()->create(['deliverable_version_id' => $version->id]);

        $this->assertSame($version->deliverable->assignment->project->client_id, $review->reviewed_by);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('deliverable_versions', 1);
    }

    #[DataProvider('reviewTimes')]
    public function test_review_timestamp_is_stored_and_cast_as_utc(mixed $reviewedAt): void
    {
        $review = DeliverableReview::factory()->create(['reviewed_at' => $reviewedAt])->fresh();

        $this->assertSame('2026-10-24 12:00:00', $review->getRawOriginal('reviewed_at'));
        $this->assertSame('UTC', $review->reviewed_at->timezoneName);
        $this->assertSame('2026-10-24 12:00:00', $review->reviewed_at->format('Y-m-d H:i:s'));
    }

    public function test_every_review_field_is_guarded_from_ordinary_mass_assignment(): void
    {
        $review = DeliverableReview::factory()->create();

        foreach (array_keys($review->getAttributes()) as $field) {
            $this->assertFalse($review->isFillable($field));
        }

        $this->expectException(MassAssignmentException::class);
        new DeliverableReview(['remarks' => 'Forged remarks']);
    }

    #[DataProvider('immutableFields')]
    public function test_completed_review_cannot_be_rewritten_even_with_force_fill(string $field, mixed $value): void
    {
        $review = DeliverableReview::factory()->create()->fresh();
        $before = $review->getAttributes();

        try {
            $review->forceFill([$field => $value])->save();
            $this->fail('Completed reviews must be immutable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be changed', $exception->getMessage());
        }

        $this->assertSame($before, $review->fresh()->getAttributes());
    }

    public function test_completed_review_cannot_be_deleted(): void
    {
        $review = DeliverableReview::factory()->create();

        try {
            $review->delete();
            $this->fail('Completed reviews must be preserved.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('must be preserved', $exception->getMessage());
        }

        $this->assertModelExists($review);
    }

    public function test_one_review_per_version_is_enforced_by_the_database(): void
    {
        $review = DeliverableReview::factory()->create();

        $this->expectException(QueryException::class);
        DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $review->deliverable_version_id]);
    }

    public function test_separate_versions_may_each_have_an_immutable_review(): void
    {
        $first = DeliverableReview::factory()->changesRequested()->create();
        $nextVersion = DeliverableVersion::factory()->create(['deliverable_id' => $first->version->deliverable_id]);
        $second = DeliverableReview::factory()->create(['deliverable_version_id' => $nextVersion->id]);

        $this->assertSame($first->reviewed_by, $second->reviewed_by);
        $this->assertNotSame($first->deliverable_version_id, $second->deliverable_version_id);
        $this->assertSame(DeliverableReviewDecision::ChangesRequested, $first->fresh()->decision);
        $this->assertSame(DeliverableReviewDecision::Approved, $second->fresh()->decision);
        $this->assertDatabaseCount('deliverable_reviews', 2);
    }

    #[DataProvider('reviewForeignKeys')]
    public function test_unknown_review_parent_is_rejected_by_the_database(string $field): void
    {
        $attributes = DeliverableReview::factory()->make()->getAttributes();
        $attributes[$field] = 999999;

        $this->expectException(QueryException::class);
        DB::table('deliverable_reviews')->insert($attributes);
    }

    public function test_database_prevents_deletion_of_a_reviewed_version_even_without_model_events(): void
    {
        $review = DeliverableReview::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('deliverable_versions')->where('id', $review->deliverable_version_id)->delete();
    }

    public function test_database_prevents_deletion_of_a_reviewer_without_other_history(): void
    {
        $reviewer = User::factory()->create(['role' => User::ROLE_CLIENT]);
        DeliverableReview::factory()->create(['reviewed_by' => $reviewer->id]);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $reviewer->id)->delete();
    }

    public function test_database_prevents_changing_the_referenced_version_identity(): void
    {
        $review = DeliverableReview::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('deliverable_versions')->where('id', $review->deliverable_version_id)->update(['id' => 999999]);
    }

    public function test_database_prevents_changing_the_referenced_reviewer_identity(): void
    {
        $reviewer = User::factory()->create(['role' => User::ROLE_CLIENT]);
        DeliverableReview::factory()->create(['reviewed_by' => $reviewer->id]);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $reviewer->id)->update(['id' => 999999]);
    }

    public static function reviewTimes(): array
    {
        return [
            [CarbonImmutable::parse('2026-10-24 17:30:00', 'Asia/Colombo')],
            ['2026-10-24T17:30:00+05:30'],
            ['2026-10-24 12:00:00'],
        ];
    }

    public static function immutableFields(): array
    {
        return [
            ['deliverable_version_id', 999999],
            ['reviewed_by', 999999],
            ['decision', DeliverableReviewDecision::ChangesRequested],
            ['remarks', 'A changed completed review'],
            ['reviewed_at', '2000-01-01 00:00:00'],
            ['created_at', '2000-01-01 00:00:00'],
            ['updated_at', '2000-01-01 00:00:00'],
        ];
    }

    public static function reviewForeignKeys(): array
    {
        return [['deliverable_version_id'], ['reviewed_by']];
    }
}
