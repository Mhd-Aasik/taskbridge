<?php

namespace Tests\Feature;

use App\Actions\Projects\PublishProject;
use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProjectPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    }

    public function test_a_confirmed_post_publishes_only_the_saved_project_and_sets_utc_time(): void
    {
        $project = $this->draft();
        $other = Project::factory()->create();
        $before = $project->fresh()->getAttributes();
        $this->actingAs($project->client);
        $this->post(route('client.projects.publish', $project), [
            'confirmed' => 1, 'client_id' => $other->client_id, 'projectId' => $other->id, 'project_id' => $other->id,
            'status' => 'completed', 'published_at' => '2000-01-01 00:00:00', 'role' => 'admin',
            'title' => 'Forged title', 'description' => 'Forged description', 'category_id' => $other->category_id,
            'budget' => '1.00', 'deadline' => '2099-01-01',
        ])->assertRedirect(route('client.projects.show', $project))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(ProjectStatus::Open, $project->status);
        $this->assertSame('2026-10-19 12:00:00', $project->getRawOriginal('published_at'));
        $this->assertSame('UTC', $project->published_at->timezoneName);
        foreach (['title', 'description', 'client_id', 'category_id', 'budget', 'deadline', 'created_at'] as $field) {
            $this->assertSame($before[$field], $project->getRawOriginal($field));
        }
        $this->assertSame(ProjectStatus::Draft, $other->fresh()->status);
        $this->assertNull($other->fresh()->published_at);
        $this->assertSame(User::ROLE_CLIENT, $project->client->role);
        $this->get(route('client.projects.show', $project))->assertOk()
            ->assertSeeText('Project published. It is now open for freelancer applications.')
            ->assertSeeText('19 October 2026, 5:30 pm (Asia/Colombo)')->assertSeeText('Status: Open')
            ->assertDontSeeText('Edit draft')->assertDontSeeText('Publish project');
    }

    public function test_confirmation_is_required(): void
    {
        $project = $this->draft();
        $this->actingAs($project->client)->post(route('client.projects.publish', $project))
            ->assertRedirect(route('client.projects.show', $project))->assertSessionHasErrors(['confirmed'], errorBag: 'publication');
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        $this->assertNull($project->fresh()->published_at);
        $this->get(route('client.projects.show', $project))->assertOk()->assertSeeText('Confirm publication before opening this project for applications.');
    }

    public function test_the_real_csrf_middleware_rejects_missing_tokens_and_accepts_a_valid_token(): void
    {
        $project = $this->draft();
        $this->actingAs($project->client);
        // Disable only Laravel's automatic CSRF bypass for tests; DB config stays SQLite.
        $this->app->instance('env', 'local');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertStatus(419);
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);

        $token = str_repeat('a', 40);
        $this->withSession(['_token' => $token])->post(route('client.projects.publish', $project), ['confirmed' => 1, '_token' => $token])
            ->assertRedirect(route('client.projects.show', $project));
        $this->assertSame(ProjectStatus::Open, $project->fresh()->status);
    }

    #[DataProvider('invalidSavedFields')]
    public function test_invalid_saved_fields_block_publication_and_forged_replacements_cannot_fix_them(string $field, string $invalid): void
    {
        $project = $this->draft();
        DB::table('projects')->where('id', $project->id)->update([$field => $invalid]);
        $before = $project->fresh()->getAttributes();
        $this->actingAs($project->client)->post(route('client.projects.publish', $project), [
            'confirmed' => 1, 'title' => 'Valid replacement', 'description' => 'Valid replacement',
            'budget' => '25000.00', 'deadline' => '2026-10-21',
        ])->assertRedirect(route('client.projects.show', $project))->assertSessionHasErrors([$field], errorBag: 'publication');

        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->get(route('client.projects.show', $project))->assertOk()->assertSeeText('Project not published')
            ->assertSeeText('Correct the saved draft if needed, then confirm publication again.')->assertSeeText('Edit draft');
        $this->get(route('client.projects.edit', $project))->assertOk();
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    public function test_an_inactive_saved_category_blocks_publication(): void
    {
        $project = $this->draft();
        $project->category->update(['is_active' => false]);
        $active = Category::factory()->create();
        $this->actingAs($project->client)->post(route('client.projects.publish', $project), ['confirmed' => 1, 'category_id' => $active->id])
            ->assertSessionHasErrors(['category_id'], errorBag: 'publication');
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        $this->assertNull($project->fresh()->published_at);
        $this->get(route('client.projects.show', $project))->assertSeeText('(inactive)')->assertSeeText('Choose an active project category.');
    }

    public function test_category_activity_is_checked_again_after_validation(): void
    {
        $project = $this->draft();
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $project): void {
            if (! $changed && str_contains($query->sql, 'categories') && str_contains($query->sql, 'aggregate')) {
                $changed = true;
                Category::query()->whereKey($project->category_id)->update(['is_active' => false]);
            }
        });
        $this->actingAs($project->client)->post(route('client.projects.publish', $project), ['confirmed' => 1])
            ->assertSessionHasErrors(['category_id'], errorBag: 'publication');
        $this->assertTrue($changed);
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }

    public function test_repeated_requests_keep_the_original_publication_time_and_all_fields(): void
    {
        $project = $this->draft();
        $this->actingAs($project->client)->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertSessionHasNoErrors();
        $before = $project->fresh()->getAttributes();
        // An already published record remains idempotent even after its deadline passes.
        $this->travel(3)->days();
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1, 'published_at' => now()->toDateTimeString()])
            ->assertRedirect(route('client.projects.show', $project))->assertSessionHasNoErrors();
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->get(route('client.projects.show', $project))->assertSeeText('This project is already published. Its original publication time has been kept.');
    }

    public function test_an_interleaved_publication_cannot_overwrite_the_first_timestamp(): void
    {
        $project = $this->draft();
        $interleaved = false;
        DB::listen(function (QueryExecuted $query) use (&$interleaved, $project): void {
            if (! $interleaved && str_contains($query->sql, 'categories') && str_contains($query->sql, 'aggregate')) {
                $interleaved = true;
                // Simulate another writer winning before the guarded UPDATE. This is not a MySQL concurrency test.
                DB::table('projects')->where('id', $project->id)->update([
                    'status' => 'open', 'published_at' => '2026-10-19 11:59:00', 'updated_at' => '2026-10-19 11:59:00',
                ]);
            }
        });
        $this->actingAs($project->client)->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($interleaved);
        $this->assertSame(ProjectStatus::Open, $project->fresh()->status);
        $this->assertSame('2026-10-19 11:59:00', $project->fresh()->getRawOriginal('published_at'));
        $this->assertSame('2026-10-19 11:59:00', $project->fresh()->getRawOriginal('updated_at'));
    }

    public function test_a_failed_write_rolls_back_both_status_and_timestamp(): void
    {
        $project = $this->draft();
        $before = $project->fresh()->getAttributes();
        $failed = false;
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && str_starts_with($query->sql, 'update "projects"')) {
                $failed = true;
                throw new RuntimeException('Simulated publication failure');
            }
        });
        try {
            app(PublishProject::class)->publish($project->client, $project->id, true);
            $this->fail('The failed write must not commit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated publication failure', $exception->getMessage());
        }
        $this->assertTrue($failed);
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    #[DataProvider('readOnlyStatuses')]
    public function test_other_statuses_cannot_be_published(ProjectStatus $status): void
    {
        $project = $this->draft();
        $project->status = $status;
        $project->save();
        $before = $project->fresh()->getAttributes();
        $this->actingAs($project->client)->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertForbidden();
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    public function test_an_open_record_without_a_publication_time_is_not_republished(): void
    {
        $project = $this->draft();
        $project->status = ProjectStatus::Open;
        $project->save();
        $this->actingAs($project->client)->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertForbidden();
        $this->assertNull($project->fresh()->published_at);
    }

    public function test_publication_rechecks_a_role_change_despite_a_cached_authenticated_user(): void
    {
        $project = $this->draft();
        $user = $project->client;
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_FREELANCER]);
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertForbidden();
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }

    public function test_publication_rechecks_persisted_ownership(): void
    {
        $project = $this->draft();
        $this->actingAs($project->client);
        $other = User::factory()->create(['role' => User::ROLE_CLIENT]);
        DB::table('projects')->where('id', $project->id)->update(['client_id' => $other->id]);
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertForbidden();
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }

    public function test_a_stale_draft_editor_cannot_overwrite_the_published_saved_record(): void
    {
        $project = $this->draft();
        $this->actingAs($project->client);
        $editor = Volt::test('client.projects.editor', ['project' => $project])->set('form.title', 'Unsaved replacement');
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertSessionHasNoErrors();
        $before = $project->fresh()->getAttributes();

        $editor->call('save')->assertForbidden();

        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->get(route('client.projects.edit', $project))->assertForbidden();
    }

    public function test_lists_and_dashboard_counts_refresh_after_publication(): void
    {
        $project = $this->draft();
        Project::factory()->count(2)->create(['status' => ProjectStatus::Open]);
        $this->actingAs($project->client);
        $this->get(route('client.dashboard'))->assertViewHas('projectCount', 1)->assertViewHas('draftCount', 1)->assertViewHas('openCount', 0);
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->get(route('client.projects.index'))->assertOk()->assertSeeText('Open')->assertDontSeeText('Edit draft')->assertSee(route('client.projects.show', $project));
        $this->get(route('client.dashboard'))->assertOk()->assertViewHas('projectCount', 1)->assertViewHas('draftCount', 0)->assertViewHas('openCount', 1)
            ->assertDontSeeText('Edit draft')->assertSee(route('client.projects.show', $project));
    }

    #[DataProvider('colomboBoundaries')]
    public function test_publication_revalidates_deadlines_against_colombo_midnight(string $utc, string $deadline, bool $valid): void
    {
        $project = $this->draft(['deadline' => $deadline]);
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $response = $this->actingAs($project->client)->post(route('client.projects.publish', $project), ['confirmed' => 1]);
        $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors(['deadline'], errorBag: 'publication');
        $this->assertSame($valid ? ProjectStatus::Open : ProjectStatus::Draft, $project->fresh()->status);
    }

    private function draft(array $overrides = []): Project
    {
        return Project::factory()->create(array_replace(['title' => 'Accessible website', 'description' => 'Saved project brief.', 'budget' => '25000.50', 'deadline' => '2026-10-20'], $overrides));
    }

    public static function invalidSavedFields(): array
    {
        return [
            'empty title' => ['title', ''], 'whitespace title' => ['title', '   '], 'long title' => ['title', str_repeat('T', 151)],
            'empty description' => ['description', ''], 'long description' => ['description', str_repeat('D', 10001)],
            'zero budget' => ['budget', '0'], 'below minimum' => ['budget', '0.99'], 'negative budget' => ['budget', '-1'],
            'over maximum' => ['budget', '10000000000.00'], 'excess stored precision' => ['budget', '1.001'], 'invalid stored budget' => ['budget', 'invalid'],
            'deadline today' => ['deadline', '2026-10-19'], 'deadline in past' => ['deadline', '2026-10-18'],
            'invalid date' => ['deadline', '2027-02-29'], 'malformed date' => ['deadline', 'invalid'],
        ];
    }

    public static function readOnlyStatuses(): array
    {
        return ['in progress' => [ProjectStatus::InProgress], 'completed' => [ProjectStatus::Completed], 'cancelled' => [ProjectStatus::Cancelled]];
    }

    public static function colomboBoundaries(): array
    {
        return ['before midnight' => ['2026-10-19 18:29:59', '2026-10-20', true], 'at midnight' => ['2026-10-19 18:30:00', '2026-10-20', false], 'new tomorrow' => ['2026-10-19 18:30:00', '2026-10-21', true]];
    }
}
