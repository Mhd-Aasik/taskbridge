<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ManagesCategories;
use Tests\TestCase;

class AdminCategoryPagesTest extends TestCase
{
    use ManagesCategories, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));
    }

    public function test_guest_redirects_admin_can_read_and_gets_never_mutate_reference_data_or_logs(): void
    {
        $category = Category::factory()->create()->fresh();
        $before = $category->getAttributes();
        $this->get(route('admin.categories.index'))->assertRedirect(route('login'));
        $this->categoryAdmin();
        $this->get(route('admin.categories.index', ['name' => 'Do not create', 'is_active' => false]))->assertOk()->assertSeeText('Manage categories')->assertSeeText($category->name);
        Volt::test('admin.categories')->call('editCategory', $category->id)->call('prepareActivation', $category->id, false);
        $this->assertSame($before, $category->fresh()->getAttributes());
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_accounts_are_denied_and_do_not_get_category_navigation(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route('admin.categories.index'))->assertForbidden();
        Volt::test('admin.categories')->assertForbidden();
        $this->get(route($role.'.dashboard'))->assertOk()->assertDontSee('href="'.route('admin.categories.index').'"', false);
    }

    #[DataProvider('staleMethods')]
    public function test_persisted_role_is_rechecked_on_every_stale_component_mutation(string $method): void
    {
        $admin = $this->categoryAdmin();
        $category = Category::factory()->create()->fresh();
        $before = $category->getAttributes();
        $page = Volt::test('admin.categories')->set('name', 'New admin category');
        if ($method === 'save-edit') {
            $page->call('editCategory', $category->id)->set('name', 'Stale edit');
        }
        if ($method === 'confirmActivation') {
            $page->call('prepareActivation', $category->id, false);
        }
        DB::table('users')->where('id', $admin->id)->update(['role' => User::ROLE_CLIENT]);
        $args = match ($method) {
            'editCategory' => [$category->id], 'prepareActivation' => [$category->id, false], default => []
        };
        $page->call(str_starts_with($method, 'save') ? 'save' : $method, ...$args)->assertForbidden();
        $this->assertSame($before, $category->fresh()->getAttributes());
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    public function test_form_retains_validation_input_then_saves_with_accessible_feedback_and_stable_slug(): void
    {
        $this->categoryAdmin();
        $page = Volt::test('admin.categories')->set('name', '!!!')->set('description', 'Keep my description')->call('save')->assertHasErrors(['name'])->assertSet('description', 'Keep my description');
        $page->set('name', 'Animation production')->call('save')->assertHasNoErrors()->assertSeeText('Category saved.')->assertSee('aria-live="polite"', false)->assertSet('savedSlug', 'animation-production');
        $category = Category::sole();
        $page->set('name', 'Updated descriptive name')->call('save')->assertHasNoErrors()->assertSet('savedSlug', 'animation-production')->call('newCategory')->assertSet('editingId', null)->assertSet('name', '');
        $this->assertSame('animation-production', $category->fresh()->slug);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('admin_action_logs', 2);
    }

    public function test_search_filter_pagination_and_deterministic_ordering_keep_overall_counts(): void
    {
        $this->categoryAdmin();
        $ids = [];
        for ($i = 1; $i <= 24; $i++) {
            $category = Category::factory()->create(['name' => sprintf('Category %02d', $i), 'is_active' => $i <= 12]);
            $ids[] = $category->id;
        }
        $page = Volt::test('admin.categories')->assertViewHas('categories', fn ($rows) => $rows->total() === 24 && $rows->modelKeys() === array_slice($ids, 0, 10));
        $page->call('setPage', 2)->assertViewHas('categories', fn ($rows) => $rows->modelKeys() === array_slice($ids, 10, 10))->set('status', 'active')->assertSet('paginators.page', 1)->assertViewHas('categories', fn ($rows) => $rows->total() === 12)
            ->set('search', 'Category 01')->assertViewHas('categories', fn ($rows) => $rows->modelKeys() === [$ids[0]])->assertViewHas('totals', fn ($totals) => (int) $totals->total === 24 && (int) $totals->active === 12);
        $page->call('clearFilters')->assertSet('search', '')->assertSet('status', 'all')->assertSet('paginators.page', 1)->assertViewHas('categories', fn ($rows) => $rows->total() === 24);
        $response = $this->get(route('admin.categories.index', ['status' => 'inactive', 'search' => 'Category', 'page' => 2]))->assertOk();
        $response->assertSeeText('Category 23')->assertSeeText('Category 24')->assertDontSeeText('Category 13');
    }

    public function test_search_wildcards_are_literal_and_user_written_name_and_description_are_escaped(): void
    {
        $this->categoryAdmin();
        $literal = Category::factory()->create(['name' => '100% exact_match', 'description' => "<script>alert('category')</script>\nSecond line."]);
        Category::factory()->create(['name' => '100 percent exact match']);
        Volt::test('admin.categories')->set('search', '100%')->assertViewHas('categories', fn ($rows) => $rows->modelKeys() === [$literal->id])->set('search', 'exact_')->assertViewHas('categories', fn ($rows) => $rows->modelKeys() === [$literal->id])
            ->assertSee(e($literal->description), false)->assertDontSee($literal->description, false)->assertSee('whitespace-pre-wrap', false)->set('search', "'; DROP TABLE categories; --")->assertViewHas('categories', fn ($rows) => $rows->isEmpty());
        $this->assertDatabaseCount('categories', 2);
    }

    #[DataProvider('invalidQueries')]
    public function test_query_parameters_are_validated_and_bounded(string $field, mixed $value): void
    {
        $this->categoryAdmin();
        $this->get(route('admin.categories.index', [$field => $value]))->assertUnprocessable();
    }

    #[DataProvider('lockedFields')]
    public function test_system_metadata_and_confirmation_snapshots_cannot_be_forged(string $field, mixed $value): void
    {
        $this->categoryAdmin();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('admin.categories')->set($field, $value);
    }

    public function test_confirmation_counts_are_real_private_and_refreshable(): void
    {
        $admin = $this->categoryAdmin();
        $category = Category::factory()->create();
        $open = Project::factory()->create(['category_id' => $category->id, 'status' => 'open', 'published_at' => now()->subHour(), 'title' => 'PRIVATE-CATEGORY-PROJECT-TITLE']);
        Project::factory()->create(['category_id' => $category->id, 'status' => 'open', 'published_at' => now()->addDay()]);
        $assignedProject = Project::factory()->create(['category_id' => $category->id]);
        $proposal = Proposal::factory()->create(['project_id' => $assignedProject->id]);
        ProjectAssignment::factory()->create(['proposal_id' => $proposal->id]);
        $page = Volt::test('admin.categories')->assertViewHas('categories', fn ($rows) => $rows->first()->projects_count === 3 && $rows->first()->open_projects_count === 2 && $rows->first()->discoverable_projects_count === 1 && $rows->first()->assigned_projects_count === 1)
            ->assertDontSee('PRIVATE-CATEGORY-PROJECT-TITLE')->assertDontSee($open->client->email)->assertDontSee(route('client.projects.show', $open))->call('prepareActivation', $category->id, false)->assertSet('confirmingActivation', true)
            ->assertSet('activationImpact', ['total' => 3, 'open' => 2, 'discoverable' => 1, 'assigned' => 1])->assertSeeText('Associated open projects leave Freelancer discovery.')->assertSeeText('Existing assigned work and history remain available.');
        Project::factory()->create(['category_id' => $category->id]);
        $page->call('confirmActivation')->assertHasErrors(['category'])->assertSet('confirmingActivation', true)->call('prepareActivation', $category->id, false)->assertSet('activationImpact.total', 4)->call('confirmActivation')->assertHasNoErrors()->assertSet('confirmingActivation', false);
        $this->assertFalse($category->fresh()->is_active);
        $this->get(route('client.projects.show', $open))->assertForbidden();
        $this->get(route('projects.plan', $assignedProject))->assertForbidden();
        $this->get(route('projects.messages', $assignedProject))->assertForbidden();
        $this->get(route('freelancer.profile.edit'))->assertForbidden();
        $this->get(route('projects.cover', $open))->assertForbidden();
        $this->get(route('account.photo', $open->client))->assertForbidden();
        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_admin_navigation_dashboard_counts_and_empty_states_work_without_delete_controls(): void
    {
        $this->categoryAdmin();
        $this->get(route('admin.categories.index'))->assertOk()->assertSeeText('No categories yet.')->assertDontSee('wire:click="delete', false);
        Category::factory()->count(2)->create(['is_active' => false]);
        Category::factory()->count(3)->create();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('href="'.route('admin.categories.index').'"', false)->assertSeeText('3 active')->assertSeeText('2 inactive')->assertViewHas('categoryCounts', fn ($counts) => (int) $counts->total === 5);
        $this->delete(route('admin.categories.index'))->assertMethodNotAllowed();
    }

    public static function nonAdminRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER]];
    }

    public static function staleMethods(): array
    {
        return [['save-create'], ['save-edit'], ['editCategory'], ['prepareActivation'], ['confirmActivation'], ['newCategory']];
    }

    public static function invalidQueries(): array
    {
        return [['search', str_repeat('a', 101)], ['search', ['bad']], ['status', 'unknown'], ['status', ['active']], ['page', 0], ['page', 10001], ['page', 'bad']];
    }

    public static function lockedFields(): array
    {
        return [['editingId', 999999], ['editVersion', 'forged'], ['saveActionId', 'forged'], ['savedSlug', 'forged'], ['activationId', 999999], ['activationTarget', true], ['activationVersion', 'forged'], ['activationImpact', ['total' => 0]], ['activationActionId', 'forged']];
    }
}
