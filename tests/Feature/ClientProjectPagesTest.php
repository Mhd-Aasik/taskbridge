<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientProjectPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_every_project_page(): void
    {
        $project = Project::factory()->create();
        foreach (['client.projects.index', 'client.projects.create', 'client.projects.edit'] as $route) {
            $this->get(route($route, $route === 'client.projects.edit' ? $project : []))->assertRedirect(route('login'));
        }
        Volt::test('client.projects.index')->assertForbidden();
        Volt::test('client.projects.editor')->assertForbidden();
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_access_routes_or_components(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $project = Project::factory()->create(['client_id' => $user->id]);
        $this->actingAs($user);

        foreach (['client.projects.index', 'client.projects.create', 'client.projects.edit'] as $route) {
            $this->get(route($route, $route === 'client.projects.edit' ? $project : []))->assertForbidden();
        }
        Volt::test('client.projects.index')->assertForbidden();
        Volt::test('client.projects.editor')->assertForbidden();
        Volt::test('client.projects.editor', ['project' => $project])->assertForbidden();
    }

    public function test_opening_list_and_create_pages_does_not_create_records_and_shows_working_empty_states(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = Category::factory()->create(['name' => 'Web Development']);
        $this->actingAs($user);

        $this->get(route('client.projects.index'))->assertOk()->assertSeeText('Your projects will appear here.')
            ->assertSeeText('Create project')->assertSee(route('client.projects.create'));
        $this->get(route('client.projects.create'))->assertOk()->assertSeeText('Save draft')
            ->assertSeeText('Drafts are private.')->assertSeeText('They are not available to freelancers.')
            ->assertSeeText($category->name)->assertSee(route('client.projects.index'));
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_empty_category_catalogue_explains_why_a_draft_cannot_be_saved(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));

        $this->get(route('client.projects.create'))->assertOk()->assertSeeText('No active categories are available. Please try again later.');
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_the_list_is_paginated_and_never_contains_other_clients_projects(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = Category::factory()->create();
        for ($number = 1; $number <= 12; $number++) {
            Project::factory()->create(['client_id' => $user->id, 'category_id' => $category->id, 'title' => sprintf('Owned brief %02d', $number)]);
        }
        Project::factory()->create(['title' => 'Another clients confidential brief']);
        $this->actingAs($user);

        $component = Volt::test('client.projects.index')
            ->assertViewHas('projects', fn ($projects) => $projects->total() === 12 && $projects->perPage() === 10)
            ->assertSeeText('Owned brief 12')->assertSeeText('Owned brief 03')
            ->assertDontSeeText('Owned brief 02')->assertDontSeeText('Another clients confidential brief');
        $component->call('setPage', 2)
            ->assertSeeText('Owned brief 02')->assertSeeText('Owned brief 01')
            ->assertDontSeeText('Owned brief 03')->assertDontSeeText('Another clients confidential brief');

        $this->get(route('client.projects.index', ['page' => 2]))->assertOk()->assertSeeText('Owned brief 01')->assertDontSeeText('Another clients confidential brief');
    }

    public function test_the_list_shows_real_details_and_only_permits_editing_drafts(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = Category::factory()->create(['name' => 'Web Development']);
        $projects = [];
        foreach (ProjectStatus::cases() as $status) {
            $projects[] = Project::factory()->create([
                'client_id' => $user->id, 'category_id' => $category->id, 'title' => 'Project '.$status->value,
                'status' => $status, 'budget' => '12345.67', 'deadline' => '2027-01-20',
            ]);
        }
        $response = $this->actingAs($user)->get(route('client.projects.index'))->assertOk()
            ->assertSeeText('Web Development')->assertSeeText('LKR 12,345.67')->assertSeeText('20 Jan 2027')
            ->assertSeeText('Draft')->assertSeeText('Open')->assertSeeText('In Progress')->assertSeeText('Completed')->assertSeeText('Cancelled');

        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        foreach ($projects as $project) {
            $this->assertSame($project->status === ProjectStatus::Draft ? 1 : 0, $xpath->query('//a[@href="'.route('client.projects.edit', $project).'"]')->length);
        }
    }

    public function test_client_dashboard_shows_only_real_owned_recent_projects_and_working_actions(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $projects = Project::factory()->count(4)->create(['client_id' => $user->id]);
        $other = Project::factory()->create(['title' => 'Private work for someone else']);

        $response = $this->actingAs($user)->get(route('client.dashboard'))->assertOk()
            ->assertViewHas('projectCount', 4)
            ->assertViewHas('recentProjects', fn ($recent) => $recent->modelKeys() === $projects->reverse()->take(3)->values()->modelKeys())
            ->assertSeeText($projects->last()->title)->assertDontSeeText($projects->first()->title)->assertDontSeeText($other->title)
            ->assertSee(route('client.projects.index'))->assertSee(route('client.projects.create'));

        foreach ($projects->reverse()->take(3) as $project) {
            $response->assertSee(route('client.projects.edit', $project));
        }
        $this->get(route('client.projects.index'))->assertOk();
        $this->get(route('client.projects.create'))->assertOk();
    }

    public function test_project_navigation_is_active_and_the_shared_account_and_theme_controls_remain_available(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $project = Project::factory()->create(['client_id' => $user->id]);
        $this->actingAs($user);

        foreach (['client.projects.index', 'client.projects.create', 'client.projects.edit'] as $route) {
            $response = $this->get(route($route, $route === 'client.projects.edit' ? $project : []))->assertOk()
                ->assertSee('aria-label="Choose theme"', false)->assertSee('x-model="$flux.appearance"', false)
                ->assertSee(route('settings.profile'))->assertSee(route('settings.password'))->assertSee(route('logout'));
            $document = new DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new DOMXPath($document);
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Workspace navigation"]//a[@href="'.route('client.projects.index').'"][@data-current]')->length);
        }
    }

    public function test_missing_projects_return_not_found(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $this->get(route('client.projects.edit', 999999))->assertNotFound();
    }

    public static function otherRoles(): array
    {
        return ['freelancer' => [User::ROLE_FREELANCER], 'admin' => [User::ROLE_ADMIN]];
    }
}
