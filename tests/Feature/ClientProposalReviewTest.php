<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Services\AccountPhotos;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientProposalReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    }

    public function test_guests_cannot_open_client_proposal_lists_or_details(): void
    {
        $proposal = $this->proposal();
        $this->get($this->listUrl($proposal->project))->assertRedirect(route('login'));
        $this->get($this->detailUrl($proposal))->assertRedirect(route('login'));
        $this->assertFalse(Gate::allows('reviewAny', [Proposal::class, $proposal->project]));
        $this->assertFalse(Gate::allows('review', [$proposal, $proposal->project]));
    }

    public function test_the_owning_client_can_read_all_offer_details_without_changing_records(): void
    {
        $proposal = $this->proposal(['cover_letter' => 'My approach to this project.', 'proposed_budget' => '35000.50']);
        $proposal->project->update(['title' => 'Accessible website', 'budget' => '25000.50']);
        $proposal->freelancer->update(['name' => 'Samara Silva']);
        $this->actingAs($proposal->project->client);
        $before = $proposal->fresh()->getAttributes();
        $projectBefore = $proposal->project->fresh()->getAttributes();

        foreach ([$this->listUrl($proposal->project), $this->detailUrl($proposal)] as $url) {
            $this->get($url)->assertOk()->assertSeeText('Samara Silva')->assertSeeText('Accessible website')
                ->assertSeeText('Status: Submitted')->assertSeeText('LKR 35,000.50')->assertSeeText('LKR 25,000.50')
                ->assertSeeText('23 October 2026')->assertSeeText('20 October 2026, 5:30 pm (Asia/Colombo)')
                ->assertSeeText('My approach to this project.')
                ->assertDontSeeText('Reject proposal')->assertDontSeeText('Assign project');
        }
        $this->get($this->detailUrl($proposal))->assertSeeText('Accept proposal');
        $this->get($this->listUrl($proposal->project))->assertDontSeeText('Accept proposal');
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertSame($projectBefore, $proposal->project->fresh()->getAttributes());
        $this->post($this->detailUrl($proposal))->assertStatus(405);
    }

    #[DataProvider('wrongRoles')]
    public function test_other_roles_are_denied_even_when_historically_recorded_as_project_owner(string $role): void
    {
        $proposal = $this->proposal();
        $owner = $proposal->project->client;
        $owner->role = $role;
        $owner->save();
        $this->actingAs($owner);
        $this->get($this->listUrl($proposal->project))->assertForbidden();
        $this->get($this->detailUrl($proposal))->assertForbidden();
        $this->assertFalse(Gate::allows('reviewAny', [Proposal::class, $proposal->project]));
        $this->assertFalse(Gate::allows('review', [$proposal, $proposal->project]));
    }

    public function test_another_client_cannot_review_any_proposals_for_the_project(): void
    {
        $proposal = $this->proposal(['cover_letter' => 'Confidential submitted offer']);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $this->get($this->listUrl($proposal->project))->assertForbidden()->assertDontSeeText($proposal->cover_letter);
        $this->get($this->detailUrl($proposal))->assertForbidden()->assertDontSeeText($proposal->cover_letter);
    }

    public function test_nested_resolution_rejects_mismatched_proposals_even_between_two_owned_projects(): void
    {
        $first = $this->proposal();
        $secondProject = $this->project(['client_id' => $first->project->client_id]);
        $second = Proposal::factory()->create(['project_id' => $secondProject->id, 'cover_letter' => 'Wrong nested project secret']);
        $this->actingAs($first->project->client);
        $this->get(route('client.projects.proposals.show', [$first->project, $second]))->assertNotFound()->assertDontSeeText($second->cover_letter);
        $this->get(route('client.projects.proposals.show', [$secondProject, $first]))->assertNotFound();
        $this->assertFalse(Gate::allows('review', [$second, $first->project]));
    }

    public function test_submitting_freelancers_keep_their_history_without_access_to_client_or_competing_reviews(): void
    {
        $own = $this->proposal();
        $competing = Proposal::factory()->create(['project_id' => $own->project_id, 'cover_letter' => 'Competing private offer']);
        $this->actingAs($own->freelancer);
        foreach ([$this->listUrl($own->project), $this->detailUrl($own), $this->detailUrl($competing)] as $url) {
            $this->get($url)->assertForbidden()->assertDontSeeText($competing->cover_letter);
        }
        $this->get(route('freelancer.proposals.index'))->assertOk()->assertSeeText(Str::limit($own->cover_letter, 220))->assertDontSeeText($competing->cover_letter);
        $this->get(route('freelancer.proposals.show', $own))->assertOk();
        $this->get(route('freelancer.proposals.show', $competing))->assertForbidden();
        $this->get(route('freelancer.projects.show', $own->project_id))->assertOk()->assertDontSeeText($competing->cover_letter)
            ->assertViewHas('project', fn ($project) => ! array_key_exists('proposals_count', $project->getAttributes()));
        $this->assertTrue(Gate::allows('view', $own));
        $this->assertFalse(Gate::allows('review', [$own, $own->project]));
    }

    public function test_persisted_role_and_project_ownership_are_rechecked(): void
    {
        $proposal = $this->proposal();
        $owner = $proposal->project->client;
        $this->actingAs($owner);
        DB::table('users')->where('id', $owner->id)->update(['role' => User::ROLE_FREELANCER]);
        $this->get($this->listUrl($proposal->project))->assertForbidden();
        $this->get($this->detailUrl($proposal))->assertForbidden();
        DB::table('users')->where('id', $owner->id)->update(['role' => User::ROLE_CLIENT]);
        DB::table('projects')->where('id', $proposal->project_id)->update(['client_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id]);
        $this->get($this->listUrl($proposal->project))->assertForbidden();
        $this->get($this->detailUrl($proposal))->assertForbidden();
    }

    #[DataProvider('discoveryChanges')]
    public function test_owner_review_and_submitter_history_survive_discovery_ineligibility(array $changes, bool $inactive = false): void
    {
        $proposal = $this->proposal();
        if ($inactive) {
            $proposal->project->category->update(['is_active' => false]);
        } else {
            DB::table('projects')->where('id', $proposal->project_id)->update($changes);
        }
        $this->actingAs($proposal->project->client);
        $before = $proposal->fresh()->getAttributes();
        $this->get($this->listUrl($proposal->project))->assertOk()->assertSeeText(Str::limit($proposal->cover_letter, 220));
        $this->get($this->detailUrl($proposal))->assertOk()->assertSeeText($proposal->cover_letter);
        $this->actingAs($proposal->freelancer);
        $this->get(route('freelancer.proposals.show', $proposal))->assertOk()->assertSeeText($proposal->cover_letter);
        $this->get(route('freelancer.projects.show', $proposal->project_id))->assertNotFound();
        $this->assertSame($before, $proposal->fresh()->getAttributes());
    }

    #[DataProvider('proposalStatuses')]
    public function test_status_filters_show_only_matching_proposals_for_this_project(ProposalStatus $status): void
    {
        $project = $this->project();
        foreach (ProposalStatus::cases() as $case) {
            Proposal::factory()->count(2)->create(['project_id' => $project->id, 'status' => $case]);
        }
        $this->proposal(['status' => $status]);
        $this->actingAs($project->client);
        $this->get($this->listUrl($project, ['status' => $status->value]))->assertOk()
            ->assertViewHas('proposals', fn ($page) => $page->total() === 2 && $page->every(fn ($proposal) => $proposal->project_id === $project->id && $proposal->status === $status))
            ->assertViewHas('project', fn ($project) => $project->proposals_count === 8);
    }

    #[DataProvider('sortOptions')]
    public function test_sorting_uses_the_whitelist_and_a_deterministic_id_tie_breaker(string $sort, array $order): void
    {
        $project = $this->project();
        $proposals = [
            Proposal::factory()->create(['project_id' => $project->id, 'proposed_budget' => '500.00', 'proposed_deadline' => '2026-10-23', 'submitted_at' => '2026-10-19 09:00:00']),
            Proposal::factory()->create(['project_id' => $project->id, 'proposed_budget' => '100.10', 'proposed_deadline' => '2026-10-21', 'submitted_at' => '2026-10-20 10:00:00']),
            Proposal::factory()->create(['project_id' => $project->id, 'proposed_budget' => '100.10', 'proposed_deadline' => '2026-10-21', 'submitted_at' => '2026-10-20 10:00:00']),
            Proposal::factory()->create(['project_id' => $project->id, 'proposed_budget' => '1000.01', 'proposed_deadline' => '2026-10-22', 'submitted_at' => '2026-10-20 11:00:00']),
        ];
        $expected = array_map(fn ($index) => $proposals[$index]->id, $order);
        $this->actingAs($project->client);
        $this->get($this->listUrl($project, $sort ? ['sort' => $sort] : []))->assertOk()
            ->assertViewHas('proposals', fn ($page) => $page->getCollection()->modelKeys() === $expected);
    }

    public function test_budget_display_and_sorting_preserve_high_value_cents(): void
    {
        $project = $this->project(['budget' => '9999999999.99']);
        $lower = Proposal::factory()->create(['project_id' => $project->id, 'proposed_budget' => '9999999999.98']);
        $higher = Proposal::factory()->create(['project_id' => $project->id, 'proposed_budget' => '9999999999.99']);
        $this->actingAs($project->client);
        $this->get($this->listUrl($project, ['sort' => 'budget_asc']))->assertOk()
            ->assertViewHas('proposals', fn ($page) => $page->getCollection()->modelKeys() === [$lower->id, $higher->id])
            ->assertSeeText('LKR 9,999,999,999.98')->assertSeeText('LKR 9,999,999,999.99');
    }

    public function test_combined_filters_pagination_and_clear_links_preserve_only_whitelisted_query_values(): void
    {
        $project = $this->project();
        $proposals = Proposal::factory()->count(25)->create(['project_id' => $project->id, 'proposed_budget' => '1500.50', 'submitted_at' => '2026-10-20 10:00:00']);
        Proposal::factory()->create(['project_id' => $project->id, 'status' => ProposalStatus::Rejected]);
        $this->actingAs($project->client);
        $filters = ['status' => 'submitted', 'sort' => 'budget_asc'];
        $response = $this->get($this->listUrl($project, $filters + ['page' => 2, 'freelancer_id' => 999999, 'unexpected' => 'omit']))->assertOk();
        $page = $response->viewData('proposals');
        $this->assertSame(25, $page->total());
        $this->assertSame(26, $response->viewData('project')->proposals_count);
        $this->assertSame($proposals->reverse()->slice(10, 10)->values()->modelKeys(), $page->getCollection()->modelKeys());
        parse_str(parse_url($page->nextPageUrl(), PHP_URL_QUERY), $next);
        $this->assertSame(['status' => 'submitted', 'sort' => 'budget_asc', 'page' => '3'], $next);
        $this->get($page->nextPageUrl())->assertOk()->assertViewHas('proposals', fn ($page) => $page->count() === 5);
        $this->get($this->listUrl($project, $filters))->assertOk()->assertViewHas('proposals', fn ($page) => $page->currentPage() === 1);
        $xpath = $this->xpath($response->getContent());
        $form = $xpath->query('//form[@method="GET"][@action="'.$this->listUrl($project).'"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame(0, $xpath->query('.//*[@name="page"]', $form)->length);
        $this->assertSame(1, $xpath->query('//a[@href="'.$this->listUrl($project).'"][normalize-space(.)="Clear filters"]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="status"]/option[@selected][@value="submitted"]')->length);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_without_returning_proposal_results(array $query, string $field): void
    {
        $proposal = $this->proposal(['cover_letter' => 'Do not return results for invalid filters']);
        $this->actingAs($proposal->project->client);
        $this->get($this->listUrl($proposal->project, $query))->assertUnprocessable()
            ->assertSeeText('Check your filters and try again.')->assertDontSeeText($proposal->cover_letter)
            ->assertViewHas('proposals', null)->assertViewHas('filterErrors', fn ($errors) => $errors->has($field));
    }

    public function test_review_exposes_only_display_names_and_escapes_names_titles_and_cover_letters(): void
    {
        Storage::fake('avatars');
        $letter = "First line.\n<script>alert('unsafe')</script> & plain text.\n".str_repeat('Details. ', 50)."\nFinal line.";
        $proposal = $this->proposal(['cover_letter' => $letter]);
        $proposal->project->update(['title' => '<img src=x onerror=alert(1)> Private brief']);
        $freelancer = $proposal->freelancer;
        $freelancer->update(['name' => 'Samara <script>name</script>', 'email' => 'private-freelancer@example.test']);
        $this->actingAs($freelancer);
        $freelancer = app(AccountPhotos::class)->replace($freelancer, UploadedFile::fake()->image('private.png'));
        $profile = FreelancerProfile::factory()->create([
            'user_id' => $freelancer->id, 'headline' => 'Private professional headline', 'bio' => 'Private biography text',
            'location' => 'Private location', 'portfolio_url' => 'https://private-portfolio.example.test', 'is_public' => false,
        ]);
        $this->actingAs($proposal->project->client);
        foreach ([$this->listUrl($proposal->project), $this->detailUrl($proposal)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee(e($freelancer->name), false)->assertDontSee($freelancer->name, false)
                ->assertSee(e($proposal->project->title), false)->assertDontSee($proposal->project->title, false)
                ->assertDontSee($freelancer->email)->assertDontSee($freelancer->avatar_path)->assertDontSee(route('account.photo', $freelancer))
                ->assertDontSee($freelancer->password)->assertDontSeeText($profile->headline)->assertDontSeeText($profile->bio)
                ->assertDontSeeText($profile->location)->assertDontSee($profile->portfolio_url);
            $data = $response->viewData();
            $loaded = $data['proposal'] ?? $data['proposals']->first();
            $this->assertSame(['id', 'name'], array_keys($loaded->freelancer->getAttributes()));
            $this->assertFalse($loaded->freelancer->relationLoaded('freelancerProfile'));
        }
        $this->get($this->detailUrl($proposal))->assertSee(e($letter), false)->assertDontSee($letter, false)->assertSee('whitespace-pre-wrap', false);
        $this->get($this->listUrl($proposal->project))->assertSee(e(Str::limit($letter, 220)), false)->assertDontSee('Final line.');
        $this->get(route('account.photo', $freelancer))->assertForbidden();
        Storage::disk('avatars')->assertExists($freelancer->avatar_path);
    }

    public function test_project_links_and_counts_and_dashboard_totals_are_owner_scoped(): void
    {
        $proposal = $this->proposal();
        $project = $proposal->project;
        Proposal::factory()->count(2)->create(['project_id' => $project->id]);
        $second = $this->project(['client_id' => $project->client_id]);
        Proposal::factory()->count(2)->create(['project_id' => $second->id]);
        Proposal::factory()->count(4)->create();
        $this->actingAs($project->client);
        $this->get(route('client.dashboard'))->assertOk()->assertViewHas('receivedProposalCount', 5)
            ->assertViewHas('recentProjects', fn ($projects) => $projects->sum('proposals_count') === 5)
            ->assertSeeText('5 proposals received across your projects.')->assertSee($this->listUrl($project))->assertSee($this->listUrl($second));
        $this->get(route('client.projects.index'))->assertOk()->assertSee($this->listUrl($project))->assertSeeText('3 proposals');
        $this->get(route('client.projects.show', $project))->assertOk()->assertSeeText('View proposals (3)')->assertSee($this->listUrl($project));
        $this->get($this->listUrl($project))->assertOk()->assertSee($this->detailUrl($proposal));
        Volt::test('client.projects.index')->assertViewHas('projects', fn ($projects) => $projects->sum('proposals_count') === 5);
    }

    public function test_query_counts_do_not_grow_with_the_number_of_proposals_or_project_cards(): void
    {
        $proposal = $this->proposal();
        $this->actingAs($proposal->project->client);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select')) {
                $queries[] = $query->sql;
            }
        });
        $this->get($this->listUrl($proposal->project))->assertOk();
        $oneProposalQueries = count($queries);
        Proposal::factory()->count(9)->create(['project_id' => $proposal->project_id]);
        $queries = [];
        $this->get($this->listUrl($proposal->project))->assertOk();
        $this->assertCount($oneProposalQueries, $queries);
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_starts_with($sql, 'select "id", "name" from "users"')));

        $queries = [];
        $this->get(route('client.projects.index'))->assertOk();
        $oneProjectQueries = count($queries);
        Project::factory()->count(9)->create(['client_id' => $proposal->project->client_id]);
        $queries = [];
        $this->get(route('client.projects.index'))->assertOk();
        $this->assertCount($oneProjectQueries, $queries);
    }

    public function test_empty_states_and_shared_navigation_are_useful(): void
    {
        $project = $this->project();
        $this->actingAs($project->client);
        $this->get($this->listUrl($project))->assertOk()->assertSeeText('No proposals yet.');
        $proposal = Proposal::factory()->create(['project_id' => $project->id]);
        $this->get($this->listUrl($project, ['status' => 'accepted']))->assertOk()->assertSeeText('No proposals match this status.');
        $this->get($this->listUrl($project, ['page' => 2]))->assertOk()->assertSeeText('No proposals on this page.');
        foreach ([$this->listUrl($project), $this->detailUrl($proposal)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('aria-label="Choose theme"', false)
                ->assertSee(route('settings.profile'))->assertSee(route('settings.password'))->assertSee(route('logout'));
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Workspace navigation"]//a[@href="'.route('client.projects.index').'"][@data-current]')->length);
        }
        $this->get(route('client.projects.proposals.index', 999999))->assertNotFound();
        $this->get(route('client.projects.proposals.show', [$project, 999999]))->assertNotFound();
    }

    private function project(array $attributes = []): Project
    {
        return Project::factory()->create(array_replace(['status' => ProjectStatus::Open, 'published_at' => '2026-10-20 10:00:00', 'deadline' => '2026-10-23'], $attributes));
    }

    private function proposal(array $attributes = []): Proposal
    {
        return Proposal::factory()->create(array_replace(['project_id' => $this->project()->id, 'proposed_deadline' => '2026-10-23', 'submitted_at' => '2026-10-20 12:00:00'], $attributes));
    }

    private function listUrl(Project $project, array $query = []): string
    {
        return route('client.projects.proposals.index', ['project' => $project->id] + $query);
    }

    private function detailUrl(Proposal $proposal): string
    {
        return route('client.projects.proposals.show', [$proposal->project_id, $proposal]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    public static function wrongRoles(): array
    {
        return [[User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function proposalStatuses(): array
    {
        return array_map(fn (ProposalStatus $status) => [$status], ProposalStatus::cases());
    }

    public static function discoveryChanges(): array
    {
        return [
            'draft' => [['status' => 'draft']], 'in progress' => [['status' => 'in_progress']],
            'completed' => [['status' => 'completed']], 'cancelled' => [['status' => 'cancelled']],
            'unpublished' => [['published_at' => null]], 'future publication' => [['published_at' => '2026-10-20 12:00:01']],
            'expired' => [['deadline' => '2026-10-19']], 'inactive category' => [[], true],
        ];
    }

    public static function sortOptions(): array
    {
        return [['', [3, 2, 1, 0]], ['newest', [3, 2, 1, 0]], ['budget_asc', [2, 1, 0, 3]], ['budget_desc', [3, 0, 2, 1]], ['deadline', [2, 1, 3, 0]]];
    }

    public static function invalidFilters(): array
    {
        return [
            [['status' => 'unknown'], 'status'], [['status' => ['submitted']], 'status'],
            [['sort' => 'proposed_budget; drop table proposals'], 'sort'], [['sort' => ['newest']], 'sort'],
            [['page' => '0'], 'page'], [['page' => '-1'], 'page'], [['page' => '10001'], 'page'],
            [['page' => '1.5'], 'page'], [['page' => [1]], 'page'],
        ];
    }
}
