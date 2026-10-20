<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientProjectReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_review_saved_details_and_escaped_multiline_description(): void
    {
        $description = "First line.\n<script>alert('unsafe')</script> & literal text.\nLast line.";
        $project = Project::factory()->create(['description' => $description, 'budget' => '9876543210.98', 'deadline' => '2027-01-20']);
        $this->actingAs($project->client);
        $before = $project->fresh()->getAttributes();

        $this->get(route('client.projects.show', $project))->assertOk()
            ->assertSeeText($project->title)->assertSeeText($project->category->name)
            ->assertSeeText('Status: Draft')->assertSeeText('LKR 9,876,543,210.98')
            ->assertSeeText('20 January 2027')->assertSeeText('Not published')
            ->assertSee(e($description), false)->assertDontSee($description, false)
            ->assertSee('whitespace-pre-wrap', false)
            ->assertSeeText('This draft is private')->assertSeeText('Edit draft')->assertSeeText('Publish project');

        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    public function test_confirmation_is_a_labelled_dialog_with_a_csrf_protected_post_form(): void
    {
        $project = Project::factory()->create();
        $response = $this->actingAs($project->client)->get(route('client.projects.show', $project))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $dialog = $xpath->query('//dialog[@aria-labelledby="publish-heading"][@aria-describedby="publish-explanation"]');
        $this->assertSame(1, $dialog->length);
        $this->assertSame(1, $xpath->query('//*[@id="publish-heading"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="publish-explanation"]')->length);
        $forms = $xpath->query('//dialog//form[@method="POST"][@action="'.route('client.projects.publish', $project).'"]');
        $this->assertSame(1, $forms->length);
        $this->assertSame(1, $xpath->query('.//input[@name="_token"][@type="hidden"]', $forms->item(0))->length);
        $this->assertSame(1, $xpath->query('.//input[@name="confirmed"][@value="1"]', $forms->item(0))->length);
        $this->assertStringContainsString('opens this saved project for freelancer applications', $dialog->item(0)->textContent);
        $this->assertStringContainsString('Draft editing will no longer be available', $dialog->item(0)->textContent);
        $this->assertSame(1, $xpath->query('.//button[@type="button"]', $forms->item(0))->length);
    }

    public function test_guests_cannot_open_the_review_or_publish(): void
    {
        $project = Project::factory()->create();
        $this->get(route('client.projects.show', $project))->assertRedirect(route('login'));
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertRedirect(route('login'));
        Volt::test('client.projects.show', ['project' => $project])->assertForbidden();
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_review_or_publish_even_if_assigned_as_owner(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $project = Project::factory()->create(['client_id' => $user->id]);
        $this->actingAs($user);
        $this->get(route('client.projects.show', $project))->assertForbidden();
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertForbidden();
        Volt::test('client.projects.show', ['project' => $project])->assertForbidden();
        $this->assertNull($project->fresh()->published_at);
    }

    public function test_another_client_cannot_review_or_publish_the_project(): void
    {
        $project = Project::factory()->create();
        $before = $project->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $this->get(route('client.projects.show', $project))->assertForbidden();
        $this->post(route('client.projects.publish', $project), ['confirmed' => 1])->assertForbidden();
        Volt::test('client.projects.show', ['project' => $project])->assertForbidden();
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    public function test_the_review_component_locks_its_project_identity(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $this->actingAs($project->client);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('client.projects.show', ['project' => $project])->set('projectId', $other->id);
    }

    public function test_get_requests_cannot_publish_even_with_forged_query_fields(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client);
        $before = $project->fresh()->getAttributes();
        $this->get(route('client.projects.show', $project).'?confirmed=1&status=open')->assertOk();
        $this->get(route('client.projects.publish', $project).'?confirmed=1')->assertStatus(405);
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    #[DataProvider('nonDrafts')]
    public function test_other_statuses_have_read_only_reviews(ProjectStatus $status): void
    {
        $project = Project::factory()->create(['status' => $status, 'published_at' => '2027-01-05 20:00:00']);
        $this->actingAs($project->client)->get(route('client.projects.show', $project))
            ->assertOk()->assertSeeText('Status: '.str($status->value)->headline())
            ->assertSeeText('6 January 2027, 1:30 am (Asia/Colombo)')
            ->assertDontSeeText('Edit draft')->assertDontSeeText('Publish project')->assertDontSeeText('Confirm publication');
    }

    #[DataProvider('budgets')]
    public function test_review_formats_lkr_exactly_without_float_rounding(string $amount, string $formatted): void
    {
        $project = Project::factory()->create(['budget' => $amount]);
        $this->actingAs($project->client)->get(route('client.projects.show', $project))->assertOk()->assertSeeText($formatted);
    }

    public function test_review_links_exist_in_project_lists_dashboard_and_saved_editor(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client);
        foreach (['client.projects.index', 'client.dashboard', 'client.projects.edit'] as $route) {
            $this->get(route($route, $route === 'client.projects.edit' ? $project : []))
                ->assertOk()->assertSee(route('client.projects.show', $project));
        }
        $response = $this->get(route('client.projects.show', $project))->assertOk()
            ->assertSee('aria-label="Choose theme"', false)->assertSee(route('settings.profile'))->assertSee(route('logout'));
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Workspace navigation"]//a[@href="'.route('client.projects.index').'"][@data-current]')->length);
    }

    public function test_missing_projects_return_not_found(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $this->get(route('client.projects.show', 999999))->assertNotFound();
        $this->post(route('client.projects.publish', 999999), ['confirmed' => 1])->assertNotFound();
    }

    public static function otherRoles(): array
    {
        return ['freelancer' => [User::ROLE_FREELANCER], 'admin' => [User::ROLE_ADMIN]];
    }

    public static function nonDrafts(): array
    {
        return array_map(fn (ProjectStatus $status) => [$status], array_filter(ProjectStatus::cases(), fn ($status) => $status !== ProjectStatus::Draft));
    }

    public static function budgets(): array
    {
        return ['minimum' => ['1.00', 'LKR 1.00'], 'fraction' => ['25000.50', 'LKR 25,000.50'], 'large' => ['9876543210.98', 'LKR 9,876,543,210.98'], 'maximum' => ['9999999999.99', 'LKR 9,999,999,999.99']];
    }
}
