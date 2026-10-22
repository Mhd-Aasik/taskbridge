<?php

namespace Tests\Feature;

use App\Actions\Projects\PublishProject;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Enums\ProjectActivityEvent as Event;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectTask;
use App\Services\ProjectActivities;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProjectActivityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulated_unique_conflict_returns_original_winner_without_rewriting_time(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Open, 'published_at' => now('UTC')]);
        ProjectActivity::creating(function () use ($project): never {
            // Inject a durable winner after the savepoint rollback; no parallel MySQL test is implied.
            DB::afterRollBack(function () use ($project): void {
                DB::table('project_activities')->insert([
                    'project_id' => $project->id, 'project_assignment_id' => null, 'actor_id' => $project->client_id,
                    'event_type' => Event::Published->value, 'event_key' => Event::Published->value.':'.$project->id.':0',
                    'metadata' => json_encode(['record_id' => $project->id]), 'occurred_at' => '2026-11-01 10:00:00',
                    'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
                ]);
            });
            throw $this->conflict('project_activities.project_id, project_activities.event_key');
        });
        $activity = DB::transaction(fn () => app(ProjectActivities::class)->record(Event::Published, $project, $project->client));
        $this->assertSame('2026-11-01 10:00:00', $activity->occurred_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('project_activities', 1);
    }

    #[DataProvider('conflicts')]
    public function test_missing_winner_or_unrelated_database_errors_propagate_and_roll_back_business_change(string $columns): void
    {
        $project = Project::factory()->create(['deadline' => now('Asia/Colombo')->addMonth()->toDateString()]);
        $before = $project->fresh()->getAttributes();
        $failure = $this->conflict($columns);
        $this->actingAs($project->client);
        ProjectActivity::creating(fn () => throw $failure);
        try {
            app(PublishProject::class)->publish($project->client, $project->id, true);
            $this->fail('Unrecoverable errors must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertDatabaseCount('project_activities', 0);
    }

    public static function conflicts(): array
    {
        return [['project_activities.project_id, project_activities.event_key'], ['project_activities.id'], ['unrelated_table.unique_column']];
    }

    public function test_conflicting_activity_identity_is_rejected_without_repairing_history(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Open, 'published_at' => now('UTC')]);
        $record = DB::transaction(fn () => app(ProjectActivities::class)->record(Event::Published, $project, $project->client));
        DB::table('project_activities')->where('id', $record->id)->update(['metadata' => json_encode(['record_id' => 999999])]);
        $this->expectException(LogicException::class);
        DB::transaction(fn () => app(ProjectActivities::class)->record(Event::Published, $project, $project->client));
    }

    public function test_json_object_key_order_does_not_break_an_exact_retry(): void
    {
        $task = ProjectTask::factory()->create();
        $this->actingAs($task->assignment->freelancer);
        app(ChangeProjectTaskStatus::class)->change($task->assignment->project_id, $task->id, 'done', 1);
        $activity = ProjectActivity::firstOrFail();
        // MySQL normalizes JSON object ordering. Simulate that storage behavior on SQLite.
        DB::table('project_activities')->where('id', $activity->id)->update(['metadata' => json_encode([
            'task_status' => 'done', 'revision' => 2, 'record_id' => $task->id,
        ])]);
        $before = $activity->fresh()->getAttributes();
        $retry = DB::transaction(fn () => app(ProjectActivities::class)->record(Event::TaskStatusChanged, $task, auth()->user()));
        $this->assertSame($before, $retry->getAttributes());
        $this->assertDatabaseCount('project_activities', 1);
    }

    public function test_non_default_origin_connection_is_used_and_enclosing_rollback_removes_activity(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Open, 'published_at' => now('UTC')]);
        Config::set('database.connections.activity_alternate', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        try {
            Artisan::call('migrate', ['--database' => 'activity_alternate', '--force' => true]);
            $other = DB::connection('activity_alternate');
            foreach (['users', 'categories', 'projects'] as $table) {
                $other->table($table)->insert(DB::table($table)->get()->map(fn ($row) => (array) $row)->all());
            }
            $copy = Project::on('activity_alternate')->findOrFail($project->id);
            try {
                $other->transaction(function () use ($copy): void {
                    app(ProjectActivities::class)->record(Event::Published, $copy, $copy->client);
                    throw new RuntimeException('Rollback outer origin');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('Rollback outer origin', $exception->getMessage());
            }
            $this->assertSame(0, $other->table('project_activities')->count());
            $record = $other->transaction(fn () => app(ProjectActivities::class)->record(Event::Published, $copy, $copy->client));
            $this->assertSame('activity_alternate', $record->getConnectionName());
            $this->assertSame(1, $other->table('project_activities')->count());
            $this->assertDatabaseCount('project_activities', 0);
        } finally {
            DB::purge('activity_alternate');
        }
    }

    private function conflict(string $columns): UniqueConstraintViolationException
    {
        $pdo = new PDOException('UNIQUE constraint failed: '.$columns);
        $pdo->errorInfo = ['23000', 19, $pdo->getMessage()];

        return new UniqueConstraintViolationException('sqlite', 'insert simulated activity conflict', [], $pdo);
    }
}
