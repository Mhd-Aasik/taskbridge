<?php

namespace Tests\Feature;

use App\Enums\ProjectActivityEvent;
use App\Models\FreelancerProfile;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Services\ProjectActivities;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectActivityDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarded_typed_relationships_utc_time_and_no_automatic_backfill(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->assertSame(0, $milestone->fresh()->details_version);
        $this->assertDatabaseCount('project_activities', 0);
        $activity = ProjectActivity::factory()->create([
            'project_id' => $assignment->project_id, 'project_assignment_id' => $assignment->id,
            'occurred_at' => '2026-11-03T23:55:00+05:30',
        ]);
        $activity->refresh();
        $this->assertSame('2026-11-03 18:25:00', $activity->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $activity->occurred_at->timezoneName);
        $this->assertSame(ProjectActivityEvent::Published, $activity->event_type);
        $this->assertTrue($activity->project->is($assignment->project));
        $this->assertTrue($activity->assignment->is($assignment));
        $this->assertTrue($activity->actor->is($assignment->project->client));
        $this->assertTrue($assignment->activities()->first()->is($activity));
        $this->assertTrue($assignment->project->activities()->first()->is($activity));
        $this->assertTrue($activity->actor->projectActivities()->first()->is($activity));
        foreach (array_keys($activity->getAttributes()) as $field) {
            $this->assertFalse($activity->isFillable($field), $field);
        }
        $this->assertFalse($milestone->isFillable('details_version'));
        $this->assertArrayNotHasKey('event_key', $activity->toArray());
    }

    #[DataProvider('immutableOperations')]
    public function test_activity_is_append_only(string $operation): void
    {
        $activity = ProjectActivity::factory()->create();
        $activity->refresh();
        $before = $activity->getAttributes();
        try {
            $operation === 'update' ? $activity->forceFill(['metadata' => []])->save() : $activity->delete();
            $this->fail('Activity must remain immutable.');
        } catch (LogicException) {
            $this->assertSame($before, $activity->fresh()->getAttributes());
        }
    }

    public static function immutableOperations(): array
    {
        return [['update'], ['delete']];
    }

    #[DataProvider('integrityCases')]
    public function test_database_restricts_history_deletion_duplicates_and_mismatched_assignment(string $kind): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $activity = ProjectActivity::factory()->create([
            'project_id' => $assignment->project_id, 'project_assignment_id' => $assignment->id,
            // Isolate the actor FK from the existing Client/project/proposal restrictions.
            'actor_id' => User::factory()->create()->id,
        ]);
        $this->expectException(QueryException::class);
        match ($kind) {
            'duplicate' => DB::table('project_activities')->insert(array_diff_key($activity->getAttributes(), ['id' => true])),
            'project' => DB::table('projects')->where('id', $activity->project_id)->delete(),
            'assignment' => DB::table('project_assignments')->where('id', $assignment->id)->delete(),
            'actor' => DB::table('users')->where('id', $activity->actor_id)->delete(),
            'mismatch' => ProjectActivity::factory()->create(['project_assignment_id' => $assignment->id]),
            'event' => DB::table('project_activities')->where('id', $activity->id)->update(['event_type' => 'invented_event']),
        };
    }

    public static function integrityCases(): array
    {
        return array_map(fn ($kind) => [$kind], ['duplicate', 'project', 'assignment', 'actor', 'mismatch', 'event']);
    }

    public function test_recording_requires_an_allowlisted_origin_and_business_transaction(): void
    {
        $project = Project::factory()->create();
        try {
            app(ProjectActivities::class)->record(ProjectActivityEvent::Assigned, $project, $project->client);
            $this->fail('Wrong origins cannot create activity.');
        } catch (LogicException) {
            $this->assertDatabaseCount('project_activities', 0);
        }
        // RefreshDatabase supplies the enclosing test transaction; explicitly leave and restore it.
        DB::rollBack();
        try {
            app(ProjectActivities::class)->record(ProjectActivityEvent::Published, $project, $project->client);
            $this->fail('Out-of-transaction recording must fail before reading the rolled-back origin.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('business transaction', $exception->getMessage());
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_deletion_guard_preserves_actor_profile_photo_and_activity_without_raw_fk_error(): void
    {
        Storage::fake('avatars');
        $actor = User::factory()->create(['role' => User::ROLE_FREELANCER, 'avatar_path' => 'avatars/actor/photo.png']);
        Storage::disk('avatars')->put($actor->avatar_path, 'Original private photo');
        $profile = FreelancerProfile::factory()->create(['user_id' => $actor->id]);
        $activity = ProjectActivity::factory()->create(['actor_id' => $actor->id]);
        $this->actingAs($actor);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['password'])->assertSeeText('Your account cannot be deleted because it has project activity history.')->assertNoRedirect();
        $this->assertModelExists($actor);
        $this->assertModelExists($profile);
        $this->assertModelExists($activity);
        $this->assertSame('Original private photo', Storage::disk('avatars')->get($actor->avatar_path));
        $unrelated = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($unrelated);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertModelMissing($unrelated);
        $this->assertModelExists($activity);
    }
}
