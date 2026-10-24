<?php

namespace Tests\Feature;

use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\ProjectRating;
use App\Models\Proposal;
use App\Models\User;
use App\Support\LocalDemoAccounts;
use App\Support\LocalDemonstration;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SkillSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class LocalDemonstrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.env' => 'local', 'mail.default' => 'array']);
        foreach (['local', 'project_covers', 'deliverables', 'avatars'] as $disk) {
            Storage::fake($disk);
        }
        $this->seed([CategorySeeder::class, SkillSeeder::class]);
        Mail::fake();
    }

    private function accounts(): array
    {
        return [User::factory()->create(['role' => 'client']), User::factory()->create(['role' => 'freelancer'])];
    }

    public function test_demo_uses_real_workflows_and_reruns_preserve_history_and_original_records(): void
    {
        [$client, $freelancer] = $this->accounts();
        $unrelated = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $original = Project::factory()->create(['client_id' => $client->id]);
        $proposal = Proposal::factory()->create(['project_id' => $original->id, 'freelancer_id' => $freelancer->id]);
        $profile = FreelancerProfile::factory()->create(['user_id' => $freelancer->id, 'share_account_photo' => false]);
        $before = [$original->fresh()->getAttributes(), $proposal->fresh()->getAttributes(), $unrelated->fresh()->getAttributes(), $admin->fresh()->getAttributes()];
        $manifest = Storage::disk('local')->path('manifest.json');
        $first = app(LocalDemonstration::class)->run($client->id, $freelancer->id, $manifest);
        $ids = array_column($first['projects'], 'id');
        $this->assertCount(8, $ids);
        $this->assertSame(['completed' => 2, 'draft' => 2, 'in_progress' => 1, 'open' => 3], Project::whereIn('id', $ids)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->orderBy('status')->pluck('total', 'status')->all());
        $this->assertSame(3, Project::discoverable()->whereIn('id', $ids)->count());
        foreach (Project::whereIn('id', $ids)->get() as $project) {
            Storage::disk('project_covers')->assertExists($project->cover_path);
            $this->actingAs($client)->get($project->coverUrl())->assertOk();
            if ($project->status->value === 'draft') {
                $this->actingAs($freelancer)->get($project->coverUrl())->assertForbidden();
            }
        }
        $this->assertDatabaseCount('project_assignments', 3);
        $this->assertDatabaseCount('deliverable_versions', 6);
        $this->assertDatabaseCount('deliverable_reviews', 5);
        $this->assertDatabaseCount('payment_reports', 2);
        $this->assertDatabaseCount('payment_acknowledgments', 1);
        $this->assertDatabaseCount('active_disputes', 1);
        $this->assertSame(4, ProjectRating::released()->count());
        $this->assertFalse($profile->fresh()->share_account_photo);
        $this->assertTrue($profile->fresh()->is_public);
        $snapshot = [];
        foreach (['projects', 'proposals', 'project_assignments', 'deliverable_versions', 'deliverable_reviews', 'project_activities', 'notifications'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $files = Storage::disk('project_covers')->allFiles();
        $this->assertSame($first, app(LocalDemonstration::class)->run($client->id, $freelancer->id, $manifest));
        foreach ($snapshot as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertSame($files, Storage::disk('project_covers')->allFiles());
        $this->assertSame($before, [$original->fresh()->getAttributes(), $proposal->fresh()->getAttributes(), $unrelated->fresh()->getAttributes(), $admin->fresh()->getAttributes()]);
        Mail::assertNothingSent();
    }

    public function test_failed_setup_rolls_back_profile_history_and_new_files(): void
    {
        [$client, $freelancer] = $this->accounts();
        DB::table('categories')->where('slug', 'data-and-analytics')->update(['is_active' => false]);
        try {
            app(LocalDemonstration::class)->run($client->id, $freelancer->id, Storage::disk('local')->path('manifest.json'));
            $this->fail('Setup must fail with a missing active category.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('projects', 0);
            $this->assertDatabaseCount('freelancer_profiles', 0);
            $this->assertDatabaseCount('project_activities', 0);
            $this->assertDatabaseCount('notifications', 0);
            $this->assertSame([], Storage::disk('project_covers')->allFiles());
            $this->assertSame([], Storage::disk('deliverables')->allFiles());
        }
    }

    public function test_production_and_unselected_roles_are_refused(): void
    {
        [$client, $freelancer] = $this->accounts();
        config(['app.env' => 'production']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(RuntimeException::class);
        app(LocalDemonstration::class)->run($client->id, $freelancer->id);
    }

    private function changes(User $client, User $freelancer): array
    {
        return [
            'client' => ['id' => $client->id, 'expected_email' => $client->email, 'name' => 'Demo Client', 'email' => 'client@example.test', 'password' => 'local-test-password', 'password_confirmation' => 'local-test-password'],
            'freelancer' => ['id' => $freelancer->id, 'expected_email' => $freelancer->email, 'name' => 'Demo Freelancer', 'email' => 'freelancer@example.test', 'password' => 'local-test-password', 'password_confirmation' => 'local-test-password'],
        ];
    }

    public function test_selected_account_changes_revoke_only_their_sessions_and_remain_idempotent(): void
    {
        [$client, $freelancer] = $this->accounts();
        $admin = User::factory()->create(['role' => 'admin']);
        $before = $admin->fresh()->getAttributes();
        config(['session.driver' => 'database']);
        foreach ([$client, $freelancer, $admin] as $user) {
            DB::table('sessions')->insert(['id' => 'demo-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
        $input = $this->changes($client, $freelancer);
        app(LocalDemoAccounts::class)->update($input);
        foreach ([$client, $freelancer] as $user) {
            $user->refresh();
            $this->assertTrue(Hash::check('local-test-password', $user->password));
            $this->assertNull($user->email_verified_at);
            $this->assertSame(1, $user->access_version);
            $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        }
        $snapshot = [$client->getAttributes(), $freelancer->getAttributes()];
        app(LocalDemoAccounts::class)->update($input);
        $this->assertSame($snapshot, [$client->fresh()->getAttributes(), $freelancer->fresh()->getAttributes()]);
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertDatabaseHas('sessions', ['user_id' => $admin->id]);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Mail::assertNothingSent();
    }

    public function test_email_collision_refuses_both_changes_without_partial_updates(): void
    {
        [$client, $freelancer] = $this->accounts();
        User::factory()->create(['email' => 'client@example.test']);
        $before = [$client->fresh()->getAttributes(), $freelancer->fresh()->getAttributes()];
        try {
            app(LocalDemoAccounts::class)->update($this->changes($client, $freelancer));
            $this->fail('Collision must fail.');
        } catch (ValidationException) {
            $this->assertSame($before, [$client->fresh()->getAttributes(), $freelancer->fresh()->getAttributes()]);
        }
    }

    public function test_changed_email_revokes_sessions_even_when_the_requested_password_already_matches(): void
    {
        [$client, $freelancer] = $this->accounts();
        foreach ([$client, $freelancer] as $user) {
            $user->password = 'local-test-password';
            $user->saveOrFail();
        }
        app(LocalDemoAccounts::class)->update($this->changes($client, $freelancer));
        $this->assertSame(1, $client->fresh()->access_version);
        $this->assertSame(1, $freelancer->fresh()->access_version);
    }
}
