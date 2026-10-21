<?php

namespace Tests\Feature;

use App\Actions\Feedback\SubmitProjectRating;
use App\Enums\NotificationEvent;
use App\Models\ProjectRating;
use App\Services\ProjectNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProjectFeedback;
use Tests\TestCase;

class ProjectFeedbackConflictTest extends TestCase
{
    use BuildsProjectFeedback, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 18:30:00', 'UTC'));
    }

    public function test_simulated_durable_winner_is_returned_without_rewriting_content_time_or_notification(): void
    {
        $assignment = $this->completedAssignment();
        $token = (string) Str::uuid();
        ProjectRating::creating(function () use ($assignment, $token): never {
            DB::afterRollBack(function () use ($assignment, $token): void {
                // Simulated committed competitor, not actual parallel MySQL execution.
                DB::table('project_ratings')->insert(['id' => 12345, 'project_assignment_id' => $assignment->id, 'reviewer_id' => $assignment->project->client_id, 'reviewee_id' => $assignment->freelancer_id,
                    'rating' => 4, 'feedback' => 'Constructive project feedback.', 'submitted_at' => '2026-11-01 17:00:00', 'submission_id' => $token, 'created_at' => '2026-11-01 17:00:00', 'updated_at' => '2026-11-01 17:00:00']);
                app(ProjectNotifications::class)->record(NotificationEvent::ProjectFeedbackSubmitted, ProjectRating::findOrFail(12345));
            });
            throw $this->conflict('project_ratings.project_assignment_id, project_ratings.reviewer_id');
        });
        $winner = $this->submitFeedback($assignment, $assignment->project->client, [], $token);
        $this->assertSame(12345, $winner->id);
        $this->assertSame('2026-11-01 17:00:00', $winner->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 17:00:00', $winner->updated_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('project_ratings', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    #[DataProvider('conflicts')]
    public function test_missing_winner_and_unrelated_database_conflicts_propagate(string $constraint): void
    {
        $assignment = $this->completedAssignment();
        $failure = $this->conflict($constraint);
        ProjectRating::creating(function () use ($failure): never {
            throw $failure;
        });
        try {
            $this->submitFeedback($assignment, $assignment->project->client);
            $this->fail('An unrelated or missing-winner error must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertDatabaseCount('project_ratings', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_submission_identifiers_cannot_be_reused_for_another_project_or_reviewer(): void
    {
        $assignment = $this->completedAssignment();
        $token = (string) Str::uuid();
        $original = $this->submitFeedback($assignment, $assignment->project->client, [], $token);
        $this->actingAs($assignment->freelancer);
        try {
            app(SubmitProjectRating::class)->submit($assignment->project_id, ['rating' => 4, 'confirmed' => true], $token);
            $this->fail('An identifier is private to one submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('feedback', $exception->errors());
        }
        $other = $this->completedAssignment();
        try {
            $this->submitFeedback($other, $other->project->client, [], $token);
            $this->fail('A second assignment cannot reuse the identifier.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('feedback', $exception->errors());
        }
        $this->assertDatabaseCount('project_ratings', 1);
        $this->assertDatabaseHas('project_ratings', ['id' => $original->id, 'submission_id' => $token]);
    }

    private function conflict(string $columns): UniqueConstraintViolationException
    {
        $pdo = new PDOException('UNIQUE constraint failed: '.$columns);
        $pdo->errorInfo = ['23000', 19, $pdo->getMessage()];

        return new UniqueConstraintViolationException('sqlite', 'insert simulated rating conflict', [], $pdo);
    }

    public static function conflicts(): array
    {
        return [['project_ratings.project_assignment_id, project_ratings.reviewer_id'], ['project_ratings.submission_id'], ['project_ratings.id'], ['another_table.unique_column']];
    }
}
