<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $project = $this->project();
        $this->get(route('freelancer.projects.index'))->assertRedirect(route('login'));
        $this->get(route('freelancer.projects.show', $project))->assertRedirect(route('login'));
        $this->assertFalse(Gate::allows('browse', Project::class));
    }

    #[DataProvider('roles')]
    public function test_only_freelancers_can_browse_or_open_discovery_pages(string $role): void
    {
        $project = $this->project();
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        $this->assertSame($role === User::ROLE_FREELANCER, Gate::allows('browse', Project::class));
        foreach ([route('freelancer.projects.index'), route('freelancer.projects.show', $project)] as $url) {
            $response = $this->get($url);
            $role === User::ROLE_FREELANCER ? $response->assertOk() : $response->assertForbidden();
        }
    }

    public function test_eligible_projects_from_different_clients_are_available_without_mutation(): void
    {
        $today = $this->project(['title' => 'Deadline today', 'deadline' => '2026-10-20', 'published_at' => now()]);
        $later = $this->project(['title' => 'Deadline later', 'deadline' => '2026-10-22']);
        $before = Project::all()->mapWithKeys(fn (Project $project) => [$project->id => $project->getAttributes()])->all();
        $this->freelancer();

        $this->get(route('freelancer.projects.index'))->assertOk()->assertSeeText($today->title)->assertSeeText($later->title);
        foreach ([$today, $later] as $project) {
            $this->get(route('freelancer.projects.show', $project))->assertOk()->assertSeeText($project->title);
        }
        $this->assertSame($before, Project::all()->mapWithKeys(fn (Project $project) => [$project->id => $project->getAttributes()])->all());
        $this->assertSame(2, Project::count());
    }

    #[DataProvider('ineligibleProjects')]
    public function test_ineligible_projects_are_excluded_from_lists_and_direct_urls(array $attributes, bool $inactive = false): void
    {
        $hidden = $this->project(array_replace(['title' => 'Never expose this brief'], $attributes));
        if ($inactive) {
            $hidden->category->update(['is_active' => false]);
        }
        $visible = $this->project(['title' => 'Eligible brief']);
        $before = $hidden->fresh()->getAttributes();
        $this->freelancer();
        $this->get(route('freelancer.projects.index'))->assertOk()->assertSeeText($visible->title)
            ->assertDontSeeText($hidden->title)->assertDontSee(route('freelancer.projects.show', $hidden));
        $this->get(route('freelancer.projects.show', $hidden))->assertNotFound()->assertDontSeeText($hidden->title);
        $this->assertFalse(Project::query()->discoverable()->whereKey($hidden->id)->exists());
        $this->assertSame($before, $hidden->fresh()->getAttributes());
    }

    public function test_detail_access_rechecks_category_and_publication_eligibility_after_listing(): void
    {
        $project = $this->project();
        $this->freelancer();
        $this->get(route('freelancer.projects.index'))->assertSeeText($project->title);
        $project->category->update(['is_active' => false]);
        $this->get(route('freelancer.projects.show', $project))->assertNotFound();
        $project->category->update(['is_active' => true]);
        $project->published_at = now()->addSecond();
        $project->save();
        $this->get(route('freelancer.projects.show', $project))->assertNotFound();
    }

    public function test_persisted_role_is_rechecked_even_if_the_authenticated_model_is_stale(): void
    {
        $project = $this->project();
        $user = $this->freelancer();
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_CLIENT]);
        $this->get(route('freelancer.projects.index'))->assertForbidden();
        $this->get(route('freelancer.projects.show', $project))->assertForbidden();
    }

    public function test_client_review_and_draft_editing_remain_owner_only(): void
    {
        $draft = Project::factory()->create();
        $this->actingAs($draft->client);
        $this->get(route('client.projects.show', $draft))->assertOk();
        $this->get(route('client.projects.edit', $draft))->assertOk();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $this->get(route('client.projects.show', $draft))->assertForbidden();
        $this->get(route('client.projects.edit', $draft))->assertForbidden();
        $this->freelancer();
        $this->get(route('client.projects.show', $draft))->assertForbidden();
        $this->get(route('client.projects.edit', $draft))->assertForbidden();
        $this->get(route('freelancer.projects.show', $draft))->assertNotFound();
    }

    public function test_search_matches_titles_and_descriptions_without_bypassing_eligibility(): void
    {
        $title = $this->project(['title' => 'Accessible website', 'description' => 'Build a site.']);
        $description = $this->project(['title' => 'Design brief', 'description' => 'An accessible experience.']);
        $other = $this->project(['title' => 'Translation', 'description' => 'Translate a handbook.']);
        $draft = $this->project(['status' => ProjectStatus::Draft, 'description' => 'Accessible secret.']);
        $this->freelancer();
        $this->get(route('freelancer.projects.index', ['search' => ' accessible ']))->assertOk()
            ->assertSeeText($title->title)->assertSeeText($description->title)->assertDontSeeText($other->title)->assertDontSeeText($draft->title)
            ->assertViewHas('filters', fn ($filters) => $filters['search'] === 'accessible');
    }

    #[DataProvider('literalSearches')]
    public function test_search_wildcards_and_sql_syntax_are_literal(string $search, string $matching, string $decoy): void
    {
        $match = $this->project(['title' => $matching, 'description' => 'A clear brief.']);
        $other = $this->project(['title' => $decoy, 'description' => 'A clear brief.']);
        $this->freelancer();
        $this->get(route('freelancer.projects.index', ['search' => $search]))->assertOk()
            ->assertSeeText($match->title)->assertDontSeeText($other->title)
            ->assertViewHas('projects', fn ($projects) => $projects->total() === 1);
    }

    public function test_search_category_and_exact_budget_limits_combine(): void
    {
        $category = Category::factory()->create(['name' => 'Website design']);
        $base = ['category_id' => $category->id, 'title' => 'Website brief', 'description' => 'A new website.'];
        $minimum = $this->project(array_replace($base, ['title' => 'Minimum website', 'budget' => '500.01']));
        $maximum = $this->project(array_replace($base, ['title' => 'Maximum website', 'budget' => '700.99']));
        $below = $this->project(array_replace($base, ['title' => 'Below website', 'budget' => '500.00']));
        $above = $this->project(array_replace($base, ['title' => 'Above website', 'budget' => '701.00']));
        $wrongCategory = $this->project(['title' => 'Other category website', 'budget' => '600.50']);
        $wrongSearch = $this->project(array_replace($base, ['title' => 'Other topic', 'description' => 'Translate a handbook.', 'budget' => '600.50']));
        $this->freelancer();
        $this->get(route('freelancer.projects.index', [
            'search' => 'website', 'category' => $category->id, 'min_budget' => '500.01', 'max_budget' => '700.99',
        ]))->assertOk()->assertSeeText($minimum->title)->assertSeeText($maximum->title)
            ->assertDontSeeText($below->title)->assertDontSeeText($above->title)->assertDontSeeText($wrongCategory->title)->assertDontSeeText($wrongSearch->title)
            ->assertViewHas('projects', fn ($projects) => $projects->total() === 2);
    }

    public function test_budget_filters_allow_zero_and_preserve_high_value_cents(): void
    {
        $match = $this->project(['budget' => '9999999999.98']);
        $this->project(['budget' => '9999999999.99']);
        $this->freelancer();
        $this->get(route('freelancer.projects.index', ['min_budget' => '9999999999.98', 'max_budget' => '9999999999.98']))->assertOk()
            ->assertViewHas('projects', fn ($projects) => $projects->getCollection()->modelKeys() === [$match->id])
            ->assertSeeText('LKR 9,999,999,999.98');
        $this->get(route('freelancer.projects.index', ['min_budget' => '0', 'max_budget' => '0.00']))->assertOk()->assertSeeText('No projects match your filters.');
        $this->get(route('freelancer.projects.index', ['max_budget' => '9999999999.99']))->assertOk()
            ->assertViewHas('projects', fn ($projects) => $projects->total() === 2);
    }

    #[DataProvider('sortOptions')]
    public function test_sorting_has_a_deterministic_id_tie_breaker(string $sort, array $order): void
    {
        $projects = [
            $this->project(['budget' => '500.00', 'deadline' => '2026-10-23', 'published_at' => '2026-10-19 09:00:00']),
            $this->project(['budget' => '100.10', 'deadline' => '2026-10-20', 'published_at' => '2026-10-20 10:00:00']),
            $this->project(['budget' => '100.10', 'deadline' => '2026-10-20', 'published_at' => '2026-10-20 10:00:00']),
            $this->project(['budget' => '1000.01', 'deadline' => '2026-10-21', 'published_at' => '2026-10-20 11:00:00']),
        ];
        $expected = array_map(fn ($index) => $projects[$index]->id, $order);
        $this->freelancer();
        $this->get(route('freelancer.projects.index', $sort ? ['sort' => $sort] : []))->assertOk()
            ->assertViewHas('projects', fn ($projects) => $projects->getCollection()->modelKeys() === $expected);
    }

    public function test_pagination_preserves_only_valid_filters_and_filter_submissions_reset_the_page(): void
    {
        $category = Category::factory()->create();
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $projects = Project::factory()->count(25)->create([
            'client_id' => $client->id, 'category_id' => $category->id, 'title' => 'Website project',
            'status' => ProjectStatus::Open, 'budget' => '25000.50', 'deadline' => '2026-10-21', 'published_at' => '2026-10-20 10:00:00',
        ]);
        $filters = ['search' => 'Website', 'category' => $category->id, 'min_budget' => '25000.5', 'max_budget' => '25000.50', 'sort' => 'budget_asc'];
        $this->freelancer();
        $response = $this->get(route('freelancer.projects.index', $filters + ['page' => 2, 'unexpected' => 'omit-me']))->assertOk();
        $paginator = $response->viewData('projects');
        $this->assertSame(25, $paginator->total());
        $this->assertSame(2, $paginator->currentPage());
        $this->assertSame($projects->reverse()->slice(10, 10)->values()->modelKeys(), $paginator->getCollection()->modelKeys());
        parse_str(parse_url($paginator->nextPageUrl(), PHP_URL_QUERY), $next);
        $this->assertSame([
            'search' => 'Website', 'category' => (string) $category->id, 'min_budget' => '25000.50', 'max_budget' => '25000.50', 'sort' => 'budget_asc', 'page' => '3',
        ], $next);
        $this->get($paginator->nextPageUrl())->assertOk()->assertViewHas('projects', fn ($page) => $page->count() === 5);
        $this->get(route('freelancer.projects.index', $filters))->assertOk()->assertViewHas('projects', fn ($page) => $page->currentPage() === 1);

        $xpath = $this->xpath($response->getContent());
        $form = $xpath->query('//form[@method="GET"][@action="'.route('freelancer.projects.index').'"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame(0, $xpath->query('.//*[@name="page"]', $form)->length);
        $this->assertSame(1, $xpath->query('//a[@href="'.route('freelancer.projects.index').'"][normalize-space(.)="Clear filters"]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="category"]/option[@selected][@value="'.$category->id.'"]')->length);
    }

    #[DataProvider('invalidParameters')]
    public function test_invalid_query_parameters_receive_actionable_errors_without_results(array $parameters, string $field): void
    {
        $project = $this->project();
        $this->freelancer();
        $this->get(route('freelancer.projects.index', $parameters))->assertUnprocessable()
            ->assertSeeText('Check your filters and try again.')->assertDontSeeText($project->title)
            ->assertViewHas('filterErrors', fn ($errors) => $errors->has($field))
            ->assertViewHas('projects', null);
    }

    public function test_inactive_or_unknown_categories_are_rejected_and_not_offered_as_filters(): void
    {
        $inactive = Category::factory()->create(['name' => 'Retired category', 'is_active' => false]);
        $active = Category::factory()->create(['name' => 'Active category']);
        $this->freelancer();
        $this->get(route('freelancer.projects.index'))->assertOk()->assertSeeText($active->name)->assertDontSeeText($inactive->name);
        foreach ([$inactive->id, 999999] as $id) {
            $this->get(route('freelancer.projects.index', ['category' => $id]))->assertUnprocessable()
                ->assertSeeText('Choose an active category, or select all categories.');
        }
    }

    #[DataProvider('colomboBoundaries')]
    public function test_eligibility_uses_colombo_calendar_days_and_includes_today(string $utc, bool $eligible): void
    {
        $project = $this->project(['deadline' => '2026-10-20', 'published_at' => '2026-10-18 10:00:00']);
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->freelancer();
        $this->get(route('freelancer.projects.index'))->assertOk()
            ->assertViewHas('projects', fn ($projects) => $projects->total() === ($eligible ? 1 : 0));
        $response = $this->get(route('freelancer.projects.show', $project));
        $eligible ? $response->assertOk() : $response->assertNotFound();
        $this->assertSame(ProjectStatus::Open, $project->fresh()->status);
    }

    public function test_details_show_only_client_display_name_and_escape_plain_text(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'Jordan & Co', 'email' => 'private-client@example.test']);
        $client->avatar_path = 'avatars/'.$client->id.'/private-photo.png';
        $client->save();
        Storage::disk('local')->put($client->avatar_path, 'Private photo contents');
        FreelancerProfile::factory()->for($client)->create(['bio' => 'Private account biography', 'is_public' => false]);
        $description = "First line.\n<script>alert('unsafe')</script> & plain text.\nLast line.";
        $project = $this->project(['client_id' => $client->id, 'description' => $description, 'budget' => '9876543210.98', 'published_at' => '2026-10-19 20:00:00']);
        $this->freelancer();
        $response = $this->get(route('freelancer.projects.show', $project))->assertOk()
            ->assertSeeText($client->name)->assertSeeText($project->category->name)->assertSeeText('LKR 9,876,543,210.98')
            ->assertSeeText('21 October 2026')->assertSeeText('20 October 2026, 1:30 am (Asia/Colombo)')
            ->assertSee('2026-10-20T01:30:00+05:30', false)->assertSee(e($description), false)->assertDontSee($description, false)
            ->assertSee('whitespace-pre-wrap', false)->assertDontSeeText('Apply')->assertDontSeeText('Edit draft')->assertDontSeeText('Publish project');
        $this->assertSame(['id', 'name'], array_keys($response->viewData('project')->client->getAttributes()));
        foreach (['freelancer.projects.index', 'freelancer.projects.show'] as $route) {
            $this->get(route($route, $route === 'freelancer.projects.show' ? $project : []))->assertOk()
                ->assertDontSee($client->email)->assertDontSee($client->avatar_path)->assertDontSeeText('Private account biography')
                ->assertDontSee(route('account.photo', $client))->assertDontSee($client->password)
                ->assertDontSee(route('client.projects.show', $project));
        }
        $this->get(route('account.photo', $client))->assertForbidden();
        Storage::disk('local')->assertExists($client->avatar_path);
    }

    public function test_list_descriptions_and_search_values_are_escaped(): void
    {
        $project = $this->project(['description' => '<img src=x onerror=alert(1)> Plain text.']);
        $this->freelancer();
        $this->get(route('freelancer.projects.index'))->assertOk()->assertSee(e($project->description), false)->assertDontSee($project->description, false);
        $this->get(route('freelancer.projects.index', ['search' => '"><script>alert(1)</script>']))->assertOk()
            ->assertSee(e('"><script>alert(1)</script>'), false)->assertDontSee('"><script>alert(1)</script>', false);
    }

    public function test_navigation_and_dashboard_lead_to_discovery_with_working_account_controls(): void
    {
        $project = $this->project();
        $this->freelancer();
        $this->get(route('freelancer.dashboard'))->assertOk()->assertSee(route('freelancer.projects.index'))
            ->assertDontSeeText('Project discovery and proposals are not available yet.');
        foreach ([route('freelancer.projects.index'), route('freelancer.projects.show', $project)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('aria-label="Choose theme"', false)
                ->assertSee(route('settings.profile'))->assertSee(route('settings.password'))->assertSee(route('logout'))->assertSee(route('freelancer.profile.edit'));
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Workspace navigation"]//a[@href="'.route('freelancer.projects.index').'"][@data-current]')->length);
            $this->assertSame(0, $xpath->query('//nav[@aria-label="Workspace navigation"]//a[@href="'.route('client.projects.index').'"]')->length);
        }
    }

    public function test_empty_states_and_unknown_pages_are_useful(): void
    {
        $this->freelancer();
        $this->get(route('freelancer.projects.index'))->assertOk()->assertSeeText('No open projects yet.')->assertSeeText('Clear filters');
        $project = $this->project();
        $this->get(route('freelancer.projects.index', ['search' => 'no matching words']))->assertOk()->assertSeeText('No projects match your filters.');
        $this->get(route('freelancer.projects.index', ['page' => 2]))->assertOk()->assertSeeText('No projects on this page.');
        $this->get(route('freelancer.projects.show', 999999))->assertNotFound();
        $this->get(route('freelancer.projects.show', 'invalid'))->assertNotFound();
        $this->post(route('freelancer.projects.show', $project))->assertStatus(405);
    }

    public function test_forged_get_fields_cannot_change_projects_or_bypass_visibility(): void
    {
        $project = $this->project();
        $draft = Project::factory()->create();
        $this->freelancer();
        $before = Project::orderBy('id')->get()->map->getAttributes()->all();
        $fields = ['status' => 'draft', 'client_id' => $draft->client_id, 'published_at' => '2000-01-01', 'role' => 'admin'];
        $this->get(route('freelancer.projects.index', $fields))->assertOk()->assertSeeText($project->title)->assertDontSeeText($draft->title);
        $this->get(route('freelancer.projects.show', ['project' => $project->id] + $fields))->assertOk();
        $this->assertSame($before, Project::orderBy('id')->get()->map->getAttributes()->all());
    }

    private function project(array $attributes = []): Project
    {
        return Project::factory()->create(array_replace([
            'status' => ProjectStatus::Open, 'published_at' => '2026-10-20 10:00:00', 'deadline' => '2026-10-21',
        ], $attributes));
    }

    private function freelancer(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $this->actingAs($user);

        return $user;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    public static function roles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'freelancer' => [User::ROLE_FREELANCER], 'admin' => [User::ROLE_ADMIN]];
    }

    public static function ineligibleProjects(): array
    {
        return [
            'draft' => [['status' => ProjectStatus::Draft]],
            'in progress' => [['status' => ProjectStatus::InProgress]],
            'completed' => [['status' => ProjectStatus::Completed]],
            'cancelled' => [['status' => ProjectStatus::Cancelled]],
            'unpublished' => [['published_at' => null]],
            'future publication' => [['published_at' => '2026-10-20 12:00:01']],
            'inactive category' => [[], true],
            'expired' => [['deadline' => '2026-10-19']],
        ];
    }

    public static function literalSearches(): array
    {
        return [
            'percent' => ['100%', '100% accessible', '1000 accessible'],
            'underscore' => ['a_b', 'Build a_b', 'Build axb'],
            'escape character' => ['Wow!', 'Wow! a brief', 'Wow a brief'],
            'SQL syntax' => ["' OR 1=1 --", "Literal ' OR 1=1 -- text", 'An unrelated brief'],
        ];
    }

    public static function sortOptions(): array
    {
        return [
            'default' => ['', [3, 2, 1, 0]], 'newest' => ['newest', [3, 2, 1, 0]],
            'low budget' => ['budget_asc', [2, 1, 0, 3]], 'high budget' => ['budget_desc', [3, 0, 2, 1]],
            'deadline' => ['deadline', [2, 1, 3, 0]],
        ];
    }

    public static function invalidParameters(): array
    {
        return [
            'long search' => [['search' => str_repeat('x', 151)], 'search'],
            'array search' => [['search' => ['unexpected']], 'search'],
            'array category' => [['category' => [1]], 'category'],
            'negative category' => [['category' => '-1'], 'category'],
            'invalid category' => [['category' => 'text'], 'category'],
            'invalid sort' => [['sort' => 'title desc; drop table projects'], 'sort'],
            'array sort' => [['sort' => ['newest']], 'sort'],
            'negative page' => [['page' => '-1'], 'page'],
            'zero page' => [['page' => '0'], 'page'],
            'large page' => [['page' => '10001'], 'page'],
            'fraction page' => [['page' => '1.5'], 'page'],
            'array page' => [['page' => [1]], 'page'],
            'excess precision' => [['min_budget' => '1.001'], 'min_budget'],
            'negative minimum' => [['min_budget' => '-1'], 'min_budget'],
            'negative maximum' => [['max_budget' => '-0.01'], 'max_budget'],
            'exponent' => [['min_budget' => '1e3'], 'min_budget'],
            'unsupported comma' => [['max_budget' => '1,000.00'], 'max_budget'],
            'over maximum' => [['max_budget' => '10000000000'], 'max_budget'],
            'huge amount' => [['min_budget' => str_repeat('9', 100)], 'min_budget'],
            'array minimum' => [['min_budget' => ['1']], 'min_budget'],
            'array maximum' => [['max_budget' => ['1']], 'max_budget'],
            'reversed range' => [['min_budget' => '10.00', 'max_budget' => '9.99'], 'max_budget'],
            'reversed high cents' => [['min_budget' => '9999999999.99', 'max_budget' => '9999999999.98'], 'max_budget'],
        ];
    }

    public static function colomboBoundaries(): array
    {
        return [
            'UTC yesterday Colombo today' => ['2026-10-19 18:30:00', true],
            'last second of deadline' => ['2026-10-20 18:29:59', true],
            'Colombo tomorrow UTC still today' => ['2026-10-20 18:30:00', false],
        ];
    }
}
