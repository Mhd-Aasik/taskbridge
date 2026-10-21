<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardRenderingTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('dashboards')]
    public function test_each_dashboard_shows_the_real_account_and_role_content(string $role, string $emptyState): void
    {
        $user = User::factory()->create(['role' => $role, 'name' => 'Alex Morgan', 'created_at' => '2026-01-12 12:00:00']);

        $this->actingAs($user)->get(route($role.'.dashboard'))
            ->assertOk()
            ->assertViewIs('dashboards.'.$role)
            ->assertSeeText(ucfirst($role).' dashboard')
            ->assertSeeText('Welcome back, Alex Morgan.')
            ->assertSeeText('Keep your profile up to date so collaborators know who they’re working with.')
            ->assertSeeText($user->email)
            ->assertSeeText('January 2026')
            ->assertSeeText($emptyState)
            ->assertDontSeeText('Laravel Starter Kit')
            ->assertDontSeeText('Repository')
            ->assertDontSeeText('Documentation');
    }

    public function test_admin_totals_match_registered_accounts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->count(3)->create(['role' => User::ROLE_CLIENT]);
        User::factory()->count(2)->create(['role' => User::ROLE_FREELANCER]);
        User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('accountCounts', ['total' => 7, 'clients' => 3, 'freelancers' => 2, 'admins' => 2]);

        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);

        foreach (['total' => 7, 'clients' => 3, 'freelancers' => 2, 'admins' => 2] as $key => $expected) {
            $this->assertSame((string) $expected, trim($xpath->query('//*[@data-testid="account-count-'.$key.'"]')->item(0)->textContent));
        }
    }

    public function test_admin_totals_include_zero_for_roles_without_accounts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('accountCounts', ['total' => 1, 'clients' => 0, 'freelancers' => 0, 'admins' => 1]);
    }

    #[DataProvider('memberRoles')]
    public function test_member_dashboards_do_not_expose_admin_totals(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route($role.'.dashboard'))
            ->assertOk()
            ->assertViewMissing('accountCounts')
            ->assertDontSee('account-count-total', false)
            ->assertDontSeeText('Registered accounts');

        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    #[DataProvider('dashboards')]
    public function test_dashboard_links_have_working_destinations(string $role, string $emptyState): void
    {
        $user = User::factory()->create(['role' => $role]);
        $response = $this->actingAs($user)->get(route($role.'.dashboard'))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $destinations = [];

        foreach ($xpath->query('//a[@href]') as $link) {
            $href = $link->getAttribute('href');
            if ($href === '#main-content') {
                $this->assertSame(1, $xpath->query('//*[@id="main-content"]')->length);
            } else {
                $allowed = [route('dashboard'), route('settings.profile'), route('settings.password'), route('notifications.index')];
                $allowed[] = route($role === User::ROLE_ADMIN ? 'admin.disputes.index' : 'disputes.index');
                if ($role === User::ROLE_ADMIN) {
                    $allowed[] = route('admin.categories.index');
                }
                if ($role !== User::ROLE_ADMIN) {
                    $allowed[] = route('messages.index');
                }
                if ($role === User::ROLE_FREELANCER) {
                    $allowed[] = route('freelancer.profile.edit');
                    $allowed[] = route('freelancer.projects.index');
                    $allowed[] = route('freelancer.proposals.index');
                    $allowed[] = route('freelancer.assignments.index');
                }
                if ($role === User::ROLE_CLIENT) {
                    $allowed[] = route('client.projects.index');
                    $allowed[] = route('client.projects.create');
                }
                $this->assertContains($href, $allowed);
                $destinations[$href] = $href;
            }
        }

        foreach ($destinations as $href) {
            if ($href === route('dashboard')) {
                $this->get($href)->assertRedirect(route($role.'.dashboard'));
            } else {
                $this->get($href)->assertOk();
            }
        }
    }

    public static function dashboards(): array
    {
        return [
            'client' => [User::ROLE_CLIENT, 'Your projects will appear here.'],
            'freelancer' => [User::ROLE_FREELANCER, 'Find your next collaboration'],
            'admin' => [User::ROLE_ADMIN, 'Open cases awaiting review'],
        ];
    }

    public static function memberRoles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'freelancer' => [User::ROLE_FREELANCER]];
    }
}
