<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('navigationLayouts')]
    public function test_shared_navigation_shows_the_role_and_active_dashboard_link(string $role, string $layout): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get(route($role.'.dashboard'));
        $response->assertOk();

        $html = $response->getContent();

        if ($layout === 'header') {
            $html = (string) $this->blade('<x-layouts.app.header>Dashboard</x-layouts.app.header>');
        }

        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);

        $this->assertStringContainsString('Role: '.ucfirst($role), $document->textContent);

        $links = $xpath->query('//a[normalize-space(.)="Dashboard"]');
        $this->assertGreaterThan(0, $links->length);

        foreach ($links as $link) {
            $this->assertSame(route('dashboard'), $link->getAttribute('href'));
            $this->assertTrue($link->hasAttribute('data-current'));
        }

        $this->assertGreaterThan(0, $xpath->query('//a[@href="'.route('settings.profile').'"]')->length);

        $logoutForms = $xpath->query('//form[@action="'.route('logout').'"]');
        $this->assertGreaterThan(0, $logoutForms->length);

        foreach ($logoutForms as $form) {
            $this->assertSame('post', strtolower($form->getAttribute('method')));
        }

        $this->get(route('dashboard'))->assertRedirect(route($role.'.dashboard'));
    }

    #[DataProvider('roles')]
    public function test_profile_settings_and_logout_work_for_every_role(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSeeText('Role: '.ucfirst($role));

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public static function roles(): array
    {
        return [
            'client' => [User::ROLE_CLIENT],
            'freelancer' => [User::ROLE_FREELANCER],
            'admin' => [User::ROLE_ADMIN],
        ];
    }

    public static function navigationLayouts(): array
    {
        $cases = [];

        foreach (self::roles() as [$role]) {
            foreach (['sidebar', 'header'] as $layout) {
                $cases[$role.' using '.$layout] = [$role, $layout];
            }
        }

        return $cases;
    }
}
