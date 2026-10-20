<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FreelancerProposalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    }

    public function test_guests_cannot_open_the_list_or_details(): void
    {
        $proposal = Proposal::factory()->create();
        $this->get(route('freelancer.proposals.index'))->assertRedirect(route('login'));
        $this->get(route('freelancer.proposals.show', $proposal))->assertRedirect(route('login'));
        Volt::test('freelancer.proposals.index')->assertForbidden();
        Volt::test('freelancer.proposals.show', ['proposal' => $proposal])->assertForbidden();
        $this->assertFalse(Gate::allows('viewAny', Proposal::class));
        $this->assertFalse(Gate::allows('view', $proposal));
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_view_tracking_even_if_historically_assigned_as_submitter(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $proposal = Proposal::factory()->create(['freelancer_id' => $user->id]);
        $this->actingAs($user);
        $this->get(route('freelancer.proposals.index'))->assertForbidden();
        $this->get(route('freelancer.proposals.show', $proposal))->assertForbidden();
        Volt::test('freelancer.proposals.index')->assertForbidden();
        Volt::test('freelancer.proposals.show', ['proposal' => $proposal])->assertForbidden();
    }

    public function test_read_only_details_show_the_own_offer_and_escape_all_written_content(): void
    {
        $letter = "A clear plan.\n<script>alert('unsafe')</script> & literal text.\nFinal line.";
        $proposal = Proposal::factory()->create(['cover_letter' => $letter, 'proposed_budget' => '9876543210.98', 'proposed_deadline' => '2026-10-23', 'submitted_at' => '2026-10-19 20:00:00']);
        $proposal->project->update(['title' => '<img src=x onerror=alert(1)> A project', 'budget' => '25000.50']);
        $this->actingAs($proposal->freelancer);
        $before = $proposal->fresh()->getAttributes();
        $this->get(route('freelancer.proposals.show', $proposal))->assertOk()->assertSee(e($proposal->project->title), false)
            ->assertDontSee($proposal->project->title, false)->assertSee(e($letter), false)->assertDontSee($letter, false)
            ->assertSeeText('LKR 9,876,543,210.98')->assertSeeText('LKR 25,000.50')->assertSeeText('23 October 2026')
            ->assertSeeText('20 October 2026, 1:30 am (Asia/Colombo)')->assertSee('whitespace-pre-wrap', false)
            ->assertSeeText('Status: Submitted')->assertDontSeeText('Edit proposal')->assertDontSeeText('Withdraw proposal');
        $this->get(route('freelancer.proposals.index'))->assertOk()->assertSee(e($proposal->project->title), false)->assertDontSee($proposal->project->title, false);
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->post(route('freelancer.proposals.show', $proposal))->assertStatus(405);
    }

    public function test_lists_are_isolated_paginated_and_ordered_by_submission_then_id(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $proposals = Proposal::factory()->count(23)->create(['freelancer_id' => $user->id, 'submitted_at' => '2026-10-20 10:00:00']);
        $other = Proposal::factory()->create(['cover_letter' => 'Secret other proposal']);
        $other->project->update(['title' => 'Secret other project']);
        $this->actingAs($user);
        $component = Volt::test('freelancer.proposals.index')
            ->assertViewHas('proposals', fn ($page) => $page->total() === 23 && $page->getCollection()->modelKeys() === $proposals->reverse()->take(10)->values()->modelKeys())
            ->assertDontSee('Secret other project')->assertDontSee('Secret other proposal');
        $component->call('gotoPage', 2)->assertViewHas('proposals', fn ($page) => $page->getCollection()->modelKeys() === $proposals->reverse()->slice(10, 10)->values()->modelKeys());
        $component->call('gotoPage', 3)->assertViewHas('proposals', fn ($page) => $page->count() === 3);
    }

    #[DataProvider('historicalChanges')]
    public function test_history_remains_accessible_when_the_project_leaves_discovery(array $changes, bool $inactive = false): void
    {
        $proposal = $this->proposal();
        if ($inactive) {
            $proposal->project->category->update(['is_active' => false]);
        } else {
            DB::table('projects')->where('id', $proposal->project_id)->update($changes);
        }
        $this->actingAs($proposal->freelancer);
        $before = $proposal->fresh()->getAttributes();
        $this->get(route('freelancer.projects.show', $proposal->project_id))->assertNotFound();
        $this->get(route('freelancer.proposals.index'))->assertOk()->assertSeeText($proposal->project->title);
        $this->get(route('freelancer.proposals.show', $proposal))->assertOk()->assertSeeText($proposal->cover_letter)
            ->assertSeeText('Your proposal history remains available here.')->assertDontSee(route('freelancer.projects.show', $proposal->project_id));
        $this->assertSame($before, $proposal->fresh()->getAttributes());
    }

    #[DataProvider('statuses')]
    public function test_all_statuses_remain_read_only_and_existing_proposal_links_replace_submission(ProposalStatus $status): void
    {
        $proposal = $this->proposal(['status' => $status]);
        $this->actingAs($proposal->freelancer);
        $this->get(route('freelancer.proposals.show', $proposal))->assertOk()->assertSeeText('Status: '.str($status->value)->headline());
        $this->get(route('freelancer.projects.show', $proposal->project_id))->assertOk()->assertSeeText('View your proposal')
            ->assertSee(route('freelancer.proposals.show', $proposal))->assertDontSee(route('freelancer.proposals.create', $proposal->project_id));
        $this->get(route('freelancer.proposals.create', $proposal->project_id))->assertOk()->assertSeeText('You have already submitted a proposal')
            ->assertSee(route('freelancer.proposals.show', $proposal))->assertDontSee('wire:submit="submit"', false);
        $this->assertFalse(Gate::allows('update', $proposal));
        $this->assertFalse(Gate::allows('delete', $proposal));
    }

    public function test_another_freelancers_proposal_does_not_replace_the_actors_submission_action(): void
    {
        $proposal = $this->proposal();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]));
        $this->get(route('freelancer.projects.show', $proposal->project_id))->assertOk()->assertSee(route('freelancer.proposals.create', $proposal->project_id))
            ->assertDontSee(route('freelancer.proposals.show', $proposal));
        $this->get(route('freelancer.proposals.show', $proposal))->assertForbidden()->assertDontSeeText($proposal->cover_letter);
        Volt::test('freelancer.proposals.show', ['proposal' => $proposal])->assertForbidden();
    }

    public function test_tracking_does_not_expose_client_account_data(): void
    {
        $proposal = $this->proposal();
        $client = $proposal->project->client;
        $client->email = 'client-secret@example.test';
        $client->avatar_path = 'private/client-secret.png';
        $client->save();
        $this->actingAs($proposal->freelancer);
        foreach ([route('freelancer.proposals.index'), route('freelancer.proposals.show', $proposal)] as $url) {
            $this->get($url)->assertOk()->assertDontSee($client->email)->assertDontSee($client->avatar_path)
                ->assertDontSee(route('account.photo', $client))->assertDontSee($client->password)
                ->assertDontSee(route('client.projects.show', $proposal->project_id));
        }
    }

    public function test_stale_tracking_components_recheck_persisted_role_and_ownership(): void
    {
        $proposal = $this->proposal();
        $user = $proposal->freelancer;
        $this->actingAs($user);
        $page = Volt::test('freelancer.proposals.show', ['proposal' => $proposal]);
        $list = Volt::test('freelancer.proposals.index');
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_CLIENT]);
        $page->call('$refresh')->assertForbidden();
        $list->call('$refresh')->assertForbidden();
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_FREELANCER]);
        $page = Volt::test('freelancer.proposals.show', ['proposal' => $proposal]);
        DB::table('proposals')->where('id', $proposal->id)->update(['freelancer_id' => User::factory()->create()->id]);
        $page->call('$refresh')->assertForbidden();
    }

    public function test_proposal_identity_is_locked(): void
    {
        $proposal = $this->proposal();
        $this->actingAs($proposal->freelancer);
        $page = Volt::test('freelancer.proposals.show', ['proposal' => $proposal]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('proposalId', $this->proposal()->id);
    }

    public function test_navigation_dashboard_account_links_and_empty_states_work(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $this->actingAs($user);
        $this->get(route('freelancer.proposals.index'))->assertOk()->assertSeeText('Your proposal history')->assertSee(route('freelancer.projects.index'));
        $this->get(route('freelancer.dashboard'))->assertOk()->assertSee(route('freelancer.proposals.index'))->assertDontSeeText('Proposals will be available in a later step.');
        $proposal = $this->proposal(['freelancer_id' => $user->id]);
        foreach ([route('freelancer.proposals.index'), route('freelancer.proposals.show', $proposal)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('aria-label="Choose theme"', false)
                ->assertSee(route('settings.profile'))->assertSee(route('settings.password'))->assertSee(route('logout'));
            $document = new DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new DOMXPath($document);
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Workspace navigation"]//a[@href="'.route('freelancer.proposals.index').'"][@data-current]')->length);
        }
        $this->get(route('freelancer.proposals.show', 999999))->assertNotFound();
    }

    private function proposal(array $attributes = []): Proposal
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Open, 'published_at' => '2026-10-20 10:00:00', 'deadline' => '2026-10-23']);

        return Proposal::factory()->create(array_replace(['project_id' => $project->id, 'submitted_at' => '2026-10-20 11:00:00'], $attributes));
    }

    public static function otherRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_ADMIN]];
    }

    public static function statuses(): array
    {
        return array_map(fn (ProposalStatus $status) => [$status], ProposalStatus::cases());
    }

    public static function historicalChanges(): array
    {
        return [
            'draft' => [['status' => 'draft']], 'in progress' => [['status' => 'in_progress']],
            'completed' => [['status' => 'completed']], 'cancelled' => [['status' => 'cancelled']],
            'unpublished' => [['published_at' => null]], 'future publication' => [['published_at' => '2026-10-20 12:00:01']],
            'expired' => [['deadline' => '2026-10-19']], 'inactive category' => [[], true],
        ];
    }
}
