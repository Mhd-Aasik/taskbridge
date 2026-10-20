<?php

namespace Tests\Feature;

use App\Actions\Proposals\AcceptProposal;
use App\Enums\ProjectStatus;
use App\Models\FreelancerProfile;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use App\Services\AccountPhotos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FreelancerAssignmentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    }

    public function test_guests_are_redirected_from_both_pages(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->get(route('freelancer.assignments.index'))->assertRedirect(route('login'));
        $this->get($this->url($assignment))->assertRedirect(route('login'));
    }

    #[DataProvider('wrongRoles')]
    public function test_only_the_assigned_freelancer_can_access_the_workspace(string $role): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $user = $assignment->freelancer;
        $user->forceFill(['role' => $role])->save();
        $this->actingAs($user)->get(route('freelancer.assignments.index'))->assertForbidden();
        $this->get($this->url($assignment))->assertForbidden();
        $this->actingAs($assignment->project->client)->get($this->url($assignment))->assertForbidden();
    }

    public function test_another_freelancer_and_competing_submitter_cannot_read_the_assignment(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $other = Proposal::factory()->create(['project_id' => $assignment->project_id, 'cover_letter' => 'Competitor private letter']);
        $this->actingAs($other->freelancer)->get($this->url($assignment))->assertForbidden();
        $this->get(route('freelancer.assignments.index'))->assertOk()->assertSeeText('No assigned projects yet.')->assertDontSeeText($assignment->project->title);
        $this->get(route('freelancer.proposals.show', $other))->assertOk()->assertSeeText($other->cover_letter)->assertDontSee($this->url($assignment));
        $this->get(route('freelancer.proposals.show', $assignment->proposal))->assertForbidden();
        $this->actingAs($assignment->freelancer)->get(route('freelancer.proposals.show', $other))->assertForbidden();
    }

    public function test_list_is_isolated_paginated_and_deterministically_ordered(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $assignments = collect();
        for ($i = 0; $i < 12; $i++) {
            $proposal = Proposal::factory()->create(['freelancer_id' => $user->id]);
            $assignments->push(ProjectAssignment::factory()->create(['proposal_id' => $proposal->id]));
        }
        $unrelated = ProjectAssignment::factory()->create();
        $this->actingAs($user)->get(route('freelancer.assignments.index'))->assertOk()
            ->assertViewHas('assignments', fn ($page) => $page->total() === 12 && $page->getCollection()->modelKeys() === $assignments->reverse()->take(10)->pluck('id')->all())
            ->assertSeeText('12 assigned projects.')->assertDontSeeText($unrelated->project->title);
        $this->get(route('freelancer.assignments.index', ['page' => 2]))->assertOk()
            ->assertViewHas('assignments', fn ($page) => $page->count() === 2 && $page->currentPage() === 2);
    }

    public function test_both_parties_see_real_assignment_details_counts_and_links(): void
    {
        $proposal = Proposal::factory()->create(['proposed_budget' => '23456.78', 'proposed_deadline' => '2026-10-23']);
        $project = $proposal->project;
        $project->forceFill(['deadline' => '2026-10-25'])->save();
        $competitor = Proposal::factory()->create(['project_id' => $project->id]);
        $this->actingAs($project->client);
        $result = app(AcceptProposal::class)->accept($project->id, $proposal->id, true);
        $assignment = $result['assignment'];
        foreach ([route('client.projects.show', $project), route('client.projects.proposals.index', $project), route('client.projects.proposals.show', [$project, $proposal])] as $url) {
            $this->get($url)->assertOk()->assertSeeText('Assigned freelancer: '.$proposal->freelancer->name)
                ->assertSeeText('LKR 23,456.78')->assertSeeText('23 October 2026')->assertSeeText('20 October 2026, 5:30 pm (Asia/Colombo)')
                ->assertSee(route('client.projects.proposals.show', [$project, $proposal]))->assertDontSeeText('Confirm acceptance');
        }
        $this->get(route('client.dashboard'))->assertOk()->assertViewHas('assignedProjectCount', 1)->assertSeeText('1 project assigned to freelancers.')
            ->assertSeeText($proposal->freelancer->name)->assertSeeText('In Progress');
        $this->get(route('client.projects.index'))->assertOk()->assertSeeText('Assigned to '.$proposal->freelancer->name);
        $this->get(route('client.projects.edit', $project))->assertForbidden();
        $this->actingAs($proposal->freelancer);
        $this->get($this->url($assignment))->assertOk()->assertSeeText($project->title)->assertSeeText($project->description)
            ->assertSeeText('Client: '.$project->client->name)->assertSeeText('LKR 23,456.78')->assertSeeText('23 October 2026')
            ->assertSeeText('20 October 2026, 5:30 pm (Asia/Colombo)')->assertSee(route('freelancer.proposals.show', $proposal));
        $this->get(route('freelancer.dashboard'))->assertOk()->assertViewHas('assignmentCount', 1)->assertSeeText('1 assigned project.')
            ->assertSee($this->url($assignment))->assertSee(route('freelancer.assignments.index'));
        $this->get(route('freelancer.proposals.show', $proposal))->assertOk()->assertSeeText('Status: Accepted')->assertSee($this->url($assignment));
        $this->get(route('freelancer.projects.show', $project))->assertNotFound();
        $this->actingAs($competitor->freelancer)->get(route('freelancer.proposals.show', $competitor))->assertOk()->assertSeeText('Status: Rejected')
            ->assertDontSee($this->url($assignment))->assertDontSeeText($proposal->cover_letter);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]))->get(route('client.dashboard'))->assertOk()->assertViewHas('assignedProjectCount', 0);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]))->get(route('freelancer.dashboard'))->assertOk()->assertViewHas('assignmentCount', 0);
    }

    public function test_project_changes_do_not_hide_the_assignment_or_offer_history(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $assignment->project->forceFill(['status' => ProjectStatus::Completed, 'published_at' => null, 'deadline' => '2020-01-01'])->save();
        $assignment->project->category->update(['is_active' => false]);
        $this->actingAs($assignment->freelancer)->get($this->url($assignment))->assertOk()->assertSeeText('Status: Completed');
        $this->get(route('freelancer.proposals.show', $assignment->proposal))->assertOk()->assertSee($this->url($assignment));
        $this->actingAs($assignment->project->client)->get(route('client.projects.show', $assignment->project))->assertOk()->assertSeeText('Project assignment');
    }

    public function test_private_client_and_competing_data_are_excluded_and_text_is_escaped(): void
    {
        Storage::fake('avatars');
        $assignment = ProjectAssignment::factory()->create(['agreed_budget' => '9999999999.99']);
        $client = $assignment->project->client;
        $client->update(['name' => '<script>Client</script>', 'email' => 'private-client@example.test']);
        $this->actingAs($client);
        $client = app(AccountPhotos::class)->replace($client, UploadedFile::fake()->image('private.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $client->id, 'bio' => 'Private account biography']);
        $description = "First line\n<script>alert('brief')</script>\nFinal line";
        $assignment->project->update(['title' => '<script>Project title</script>', 'description' => $description]);
        $competing = Proposal::factory()->create(['project_id' => $assignment->project_id, 'cover_letter' => 'Secret competing proposal']);
        $before = $assignment->fresh()->getAttributes();
        $this->actingAs($assignment->freelancer);
        foreach ([route('freelancer.assignments.index'), $this->url($assignment)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee(e($client->name), false)->assertDontSee($client->name, false)
                ->assertSee(e($assignment->project->fresh()->title), false)->assertDontSeeText($client->email)->assertDontSee($client->avatar_path)
                ->assertDontSee(route('account.photo', $client))->assertDontSeeText($profile->bio)->assertDontSeeText($competing->cover_letter)
                ->assertSeeText('LKR 9,999,999,999.99');
            $data = $response->viewData();
            $loaded = $data['assignment'] ?? $data['assignments']->first();
            $this->assertSame(['id', 'name'], array_keys($loaded->project->client->getAttributes()));
            $this->assertFalse($loaded->project->relationLoaded('proposals'));
        }
        $this->get($this->url($assignment))->assertSee(e($description), false)->assertDontSee($description, false)->assertSee('whitespace-pre-wrap', false);
        $this->get(route('account.photo', $client))->assertForbidden();
        $this->assertSame($before, $assignment->fresh()->getAttributes());
    }

    public function test_empty_states_navigation_and_missing_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]));
        $this->get(route('freelancer.assignments.index'))->assertOk()->assertSeeText('No assigned projects yet.')
            ->assertSee(route('freelancer.projects.index'))->assertSee('aria-label="Choose theme"', false)
            ->assertSee(route('settings.profile'))->assertSee(route('settings.password'))->assertSee(route('logout'));
        $this->get(route('freelancer.assignments.show', 999999))->assertNotFound();
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->freelancer)->get(route('freelancer.assignments.index', ['page' => 2]))->assertOk()->assertSeeText('No assignments on this page.');
        $this->post($this->url($assignment))->assertStatus(405);
    }

    public function test_persisted_freelancer_role_is_rechecked_and_queries_do_not_grow_per_assignment(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $user = $assignment->freelancer;
        $this->actingAs($user);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select')) {
                $queries[] = $query->sql;
            }
        });
        $this->get(route('freelancer.assignments.index'))->assertOk();
        $count = count($queries);
        for ($i = 0; $i < 9; $i++) {
            $proposal = Proposal::factory()->create(['freelancer_id' => $user->id]);
            ProjectAssignment::factory()->create(['proposal_id' => $proposal->id]);
        }
        $queries = [];
        $this->get(route('freelancer.assignments.index'))->assertOk();
        $this->assertCount($count, $queries);
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_CLIENT]);
        $this->get(route('freelancer.assignments.index'))->assertForbidden();
        $this->get($this->url($assignment))->assertForbidden();
    }

    public function test_account_deletion_preserves_assignment_history_and_private_resources(): void
    {
        Storage::fake('avatars');
        $assignment = ProjectAssignment::factory()->create();
        foreach ([$assignment->freelancer, $assignment->project->client] as $user) {
            $this->actingAs($user);
            $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('avatar.png'));
            $profile = FreelancerProfile::factory()->create(['user_id' => $user->id]);
            Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertNoRedirect();
            $this->assertModelExists($user);
            $this->assertModelExists($profile);
            $this->assertModelExists($assignment);
            $this->assertModelExists($assignment->proposal);
            Storage::disk('avatars')->assertExists($user->avatar_path);
        }
    }

    private function url(ProjectAssignment $assignment): string
    {
        return route('freelancer.assignments.show', $assignment);
    }

    public static function wrongRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_ADMIN]];
    }
}
