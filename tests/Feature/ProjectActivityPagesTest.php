<?php

namespace Tests\Feature;

use App\Actions\Milestones\SaveMilestone;
use App\Enums\ProjectActivityEvent as Event;
use App\Enums\ProjectStatus;
use App\Models\InAppNotification;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ProjectActivityHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectActivityPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 18:45:00', 'UTC'));
    }

    public function test_guest_redirects_and_no_rendering_side_effects_or_fake_history(): void
    {
        $project = Project::factory()->create();
        $this->get(route('projects.activity', $project))->assertRedirect(route('login'));
        $this->actingAs($project->client)->get(route('projects.activity', $project))->assertOk()
            ->assertSeeText('Activity will appear here as the project progresses.')->assertDontSee('View details');
        $this->assertDatabaseCount('project_activities', 0);
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }

    #[DataProvider('outsiders')]
    public function test_unrelated_accounts_admins_applicants_and_directory_visitors_cannot_read_history(string $kind): void
    {
        $assignment = ProjectAssignment::factory()->create();
        ProjectActivity::factory()->create(['project_id' => $assignment->project_id]);
        $user = User::factory()->create(['role' => $kind === 'client' ? User::ROLE_CLIENT : ($kind === 'admin' ? User::ROLE_ADMIN : User::ROLE_FREELANCER)]);
        if ($kind === 'applicant') {
            Proposal::factory()->create(['project_id' => $assignment->project_id, 'freelancer_id' => $user->id]);
        }
        $this->actingAs($user)->get(route('projects.activity', $assignment->project_id))->assertForbidden()->assertDontSee('Project published');
        $this->assertNull(ProjectActivityHistory::destination($user, Event::Assigned, $assignment->project, $assignment));
    }

    public static function outsiders(): array
    {
        return array_map(fn ($kind) => [$kind], ['client', 'freelancer', 'admin', 'applicant', 'directory_visitor']);
    }

    public function test_owner_sees_private_events_but_assigned_freelancer_only_sees_their_shared_interval(): void
    {
        $assignment = ProjectAssignment::factory()->create(['assigned_at' => now('UTC')]);
        $private = ProjectActivity::factory()->create(['project_id' => $assignment->project_id, 'occurred_at' => now()->subHour()]);
        $early = $this->shared($assignment, Event::MilestoneCreated, now()->subSecond());
        $shared = $this->shared($assignment, Event::Assigned, now());
        $this->shared($assignment, Event::TaskCreated, now()->addSecond());
        $this->actingAs($assignment->project->client)->get(route('projects.activity', $assignment->project_id))->assertOk()
            ->assertSeeText('Project published')->assertSeeText('Milestone added')->assertSeeText('Freelancer assigned')->assertSeeText('Task added');
        $this->actingAs($assignment->freelancer)->get(route('projects.activity', $assignment->project_id))->assertOk()
            ->assertDontSeeText('Project published')->assertDontSeeText('Milestone added')->assertSeeText('Freelancer assigned')->assertSeeText('Task added');
        $ids = ProjectActivityHistory::query($assignment->freelancer, $assignment->project, $assignment)->pluck('id')->all();
        $this->assertNotContains($private->id, $ids);
        $this->assertNotContains($early->id, $ids);
        $this->assertContains($shared->id, $ids);
    }

    #[DataProvider('participantRoles')]
    public function test_suspended_actor_is_denied_but_active_counterparty_retains_history(string $role): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->shared($assignment, Event::Assigned, now());
        $actor = $role === User::ROLE_CLIENT ? $assignment->project->client : $assignment->freelancer;
        $counterparty = $role === User::ROLE_CLIENT ? $assignment->freelancer : $assignment->project->client;
        $this->actingAs($actor);
        User::whereKey($actor->id)->update(['suspended_at' => now(), 'access_version' => 1]);
        $this->get(route('projects.activity', $assignment->project_id))->assertRedirect(route('login'));
        $this->actingAs($counterparty)->get(route('projects.activity', $assignment->project_id))->assertOk()->assertSeeText('Freelancer assigned');
    }

    public static function participantRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER]];
    }

    public function test_revoked_and_persisted_wrong_role_sessions_cannot_reuse_a_previously_authorized_page(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $actor = $assignment->freelancer;
        $this->actingAs($actor)->get(route('projects.activity', $assignment->project_id))->assertOk();
        User::whereKey($actor->id)->update(['role' => User::ROLE_CLIENT]);
        $this->get(route('projects.activity', $assignment->project_id))->assertForbidden();
        User::whereKey($actor->id)->update(['role' => User::ROLE_FREELANCER, 'access_version' => 1]);
        $this->get(route('projects.activity', $assignment->project_id))->assertRedirect(route('login'));
    }

    public function test_stable_newest_first_pagination_is_project_scoped_and_bounds_input(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $ids = [];
        for ($i = 0; $i < 17; $i++) {
            $ids[] = $this->shared($assignment, Event::TaskCreated, now(), 'pagination:'.$i)->id;
        }
        $other = ProjectActivity::factory()->create();
        $this->actingAs($assignment->freelancer);
        $first = $this->get(route('projects.activity', $assignment->project_id))->assertOk()->viewData('activities');
        $second = $this->get(route('projects.activity', ['project' => $assignment->project_id, 'page' => 2]))->assertOk()->viewData('activities');
        $this->assertSame(17, $first->total());
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $first->pluck('id')->all());
        $this->assertSame(array_slice(array_reverse($ids), 15), $second->pluck('id')->all());
        $this->assertNotContains($other->id, $first->pluck('id')->all());
        foreach (['0', '10001', 'oops', ['1']] as $page) {
            $this->getJson(route('projects.activity', ['project' => $assignment->project_id, 'page' => $page]))->assertUnprocessable()->assertJsonValidationErrors('page');
        }
    }

    #[DataProvider('participantRoles')]
    public function test_historical_access_escaped_names_safe_metadata_and_only_working_destinations(string $role): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $assignment->project->forceFill(['title' => '<script>project</script>', 'status' => ProjectStatus::Completed])->save();
        $assignment->project->client->forceFill(['name' => '<script>actor</script>', 'email' => 'private@client.test'])->save();
        $assignment->freelancer->forceFill(['email' => 'private@freelancer.test'])->save();
        foreach (Event::cases() as $event) {
            if ($event !== Event::Published) {
                $activity = $this->shared($assignment, $event, now());
                // Defensive presentation fixture: even unexpected metadata must never be dumped.
                DB::table('project_activities')->where('id', $activity->id)->update(['metadata' => json_encode(['record_id' => 1, 'task_status' => 'done', 'private' => 'Do not expose metadata'])]);
            }
        }
        $actor = $role === User::ROLE_CLIENT ? $assignment->project->client->fresh() : $assignment->freelancer;
        $this->actingAs($actor);
        $response = $this->get(route('projects.activity', $assignment->project_id))->assertOk();
        $response->assertSee('&lt;script&gt;project&lt;/script&gt;', false)->assertSee('&lt;script&gt;actor&lt;/script&gt;', false)
            ->assertDontSee('<script>project</script>', false)->assertDontSee('<script>actor</script>', false)
            ->assertDontSee($role === User::ROLE_CLIENT ? 'private@freelancer.test' : 'private@client.test')
            ->assertDontSee('Do not expose metadata')->assertDontSee('avatar_path')->assertDontSee('payment reference');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        foreach (Event::cases() as $event) {
            $destination = ProjectActivityHistory::destination($actor, $event, $assignment->project, $assignment);
            $this->assertNotNull($destination);
            $this->get($destination)->assertOk();
            $this->assertStringNotContainsString('/admin/', $destination);
            if ($role === User::ROLE_FREELANCER) {
                $this->assertStringNotContainsString('/client/', $destination);
            }
        }
    }

    public function test_real_page_links_actor_eager_loading_and_rendering_preserve_events_and_notification_state(): void
    {
        $assignment = ProjectAssignment::factory()->create(['agreed_deadline' => '2026-12-01']);
        $this->actingAs($assignment->project->client);
        $milestone = app(SaveMilestone::class)->save($assignment->project_id, null, ['title' => 'Real milestone', 'description' => 'Private notes', 'due_date' => '2026-11-10']);
        $snapshot = ProjectActivity::get()->toJson();
        $alerts = InAppNotification::get()->toJson();
        $this->get(route('client.projects.show', $assignment->project_id))->assertOk()->assertSee(route('projects.activity', $assignment->project_id), false);
        $this->get(route('projects.plan', $assignment->project_id))->assertOk()->assertSee(route('projects.activity', $assignment->project_id), false);
        $this->actingAs($assignment->freelancer)->get(route('freelancer.assignments.show', $assignment))->assertOk()->assertSee(route('projects.activity', $assignment->project_id), false);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $page = $this->get(route('projects.activity', $assignment->project_id))->assertOk()->viewData('activities');
        $this->assertTrue($page->first()->relationLoaded('actor'));
        $this->assertSame(2, count(array_filter($queries, fn ($sql) => str_contains($sql, 'from "project_activities"'))));
        $this->assertSame($snapshot, ProjectActivity::get()->toJson());
        $this->assertSame($alerts, InAppNotification::get()->toJson());
        $this->assertSame('Real milestone', $milestone->fresh()->title);
    }

    private function shared(ProjectAssignment $assignment, Event $event, $at, ?string $key = null): ProjectActivity
    {
        return ProjectActivity::factory()->create([
            'project_id' => $assignment->project_id, 'project_assignment_id' => $assignment->id,
            'event_type' => $event, 'event_key' => $key ?? $event->value.':'.$assignment->id,
            'occurred_at' => $at, 'metadata' => ['record_id' => $assignment->id, 'task_status' => 'todo'],
        ]);
    }
}
