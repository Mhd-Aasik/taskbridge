<?php

namespace Tests\Feature;

use App\Models\ProjectRating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectRatingDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_relationships_casts_guarding_and_utc_times(): void
    {
        $rating = ProjectRating::factory()->create(['submitted_at' => '2026-11-01 00:15:00+05:30'])->fresh();
        $this->assertTrue($rating->assignment->ratings()->sole()->is($rating));
        $this->assertTrue($rating->reviewer->submittedProjectRatings()->sole()->is($rating));
        $this->assertTrue($rating->reviewee->receivedProjectRatings()->sole()->is($rating));
        $this->assertTrue($rating->reviewer->isClient());
        $this->assertTrue($rating->reviewee->isFreelancer());
        $this->assertIsInt($rating->rating);
        $this->assertNull($rating->feedback);
        $this->assertTrue(Str::isUuid($rating->submission_id));
        $this->assertInstanceOf(CarbonImmutable::class, $rating->submitted_at);
        $this->assertSame('2026-10-31 18:45:00', $rating->getRawOriginal('submitted_at'));
        $this->assertSame('UTC', $rating->submitted_at->timezoneName);
        foreach (['project_assignment_id', 'reviewer_id', 'reviewee_id', 'rating', 'feedback', 'submitted_at', 'submission_id', 'created_at', 'updated_at'] as $field) {
            $this->assertFalse($rating->isFillable($field));
        }
        $this->assertArrayNotHasKey('submission_id', $rating->toArray());
        $this->assertDatabaseCount('notifications', 0);
    }

    #[DataProvider('immutableFields')]
    public function test_submitted_feedback_cannot_be_edited(string $field, mixed $value): void
    {
        $rating = ProjectRating::factory()->create()->fresh();
        $before = $rating->getAttributes();
        try {
            $rating->forceFill([$field => $value])->save();
            $this->fail('Feedback must be immutable.');
        } catch (LogicException) {
            $this->assertSame($before, $rating->fresh()->getAttributes());
        }
    }

    public function test_submitted_feedback_cannot_be_deleted_by_a_model_action(): void
    {
        $rating = ProjectRating::factory()->create();
        $this->expectException(LogicException::class);
        $rating->delete();
    }

    public function test_assignment_reviewer_pair_is_unique_even_with_another_submission_identifier(): void
    {
        $rating = ProjectRating::factory()->create();
        $duplicate = $rating->getAttributes();
        unset($duplicate['id']);
        $duplicate['submission_id'] = (string) Str::uuid();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('project_ratings')->insert($duplicate);
    }

    public function test_submission_identifier_is_unique_across_assignments(): void
    {
        $first = ProjectRating::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        ProjectRating::factory()->create(['submission_id' => $first->submission_id]);
    }

    #[DataProvider('invalidStoredFields')]
    public function test_database_rejects_invalid_scores_self_ratings_and_broken_foreign_keys(string $field, mixed $value): void
    {
        $rating = ProjectRating::factory()->create();
        $row = $rating->getAttributes();
        unset($row['id']);
        DB::table('project_ratings')->delete();
        $row[$field] = $value === 'self' ? $rating->reviewer_id : $value;
        $this->expectException(QueryException::class);
        DB::table('project_ratings')->insert($row);
    }

    public function test_assignment_and_both_account_foreign_keys_restrict_deletion(): void
    {
        $rating = ProjectRating::factory()->create();
        try {
            DB::table('project_assignments')->where('id', $rating->project_assignment_id)->delete();
            $this->fail('Assignment history must remain.');
        } catch (QueryException) {
            $this->assertModelExists($rating);
        }
        // Isolate the new user foreign keys from existing ownership/proposal foreign keys.
        foreach (['reviewer_id', 'reviewee_id'] as $field) {
            $unrelated = User::factory()->create();
            DB::table('project_ratings')->where('id', $rating->id)->update([$field => $unrelated->id]);
            try {
                DB::table('users')->where('id', $unrelated->id)->delete();
                $this->fail('Feedback participant history must remain.');
            } catch (QueryException) {
                $this->assertModelExists($unrelated);
            }
            DB::table('project_ratings')->where('id', $rating->id)->update([$field => $rating->{$field}]);
        }
    }

    public static function immutableFields(): array
    {
        return [['rating', 1], ['feedback', 'Rewritten'], ['reviewer_id', 999999], ['reviewee_id', 999999], ['project_assignment_id', 999999], ['submission_id', '00000000-0000-4000-8000-000000000001'], ['submitted_at', '2030-01-01 00:00:00'], ['created_at', '2030-01-01 00:00:00']];
    }

    public static function invalidStoredFields(): array
    {
        return [['rating', 0], ['rating', 6], ['rating', -1], ['rating', 3.5], ['rating', 'invalid'], ['reviewee_id', 'self'], ['reviewer_id', 999999], ['reviewee_id', 999999], ['project_assignment_id', 999999]];
    }
}
