<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThemeControlTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_every_role_has_one_shared_theme_dropdown_beside_the_avatar(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $routes = [$role.'.dashboard', 'settings.profile', 'settings.password'];
        if ($role === User::ROLE_FREELANCER) {
            $routes[] = 'freelancer.profile.edit';
        }

        foreach ($routes as $route) {
            $response = $this->actingAs($user)->get(route($route))->assertOk();
            $document = new DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new DOMXPath($document);

            $this->assertSame(1, $xpath->query('//header//*[@data-flux-dropdown][.//button[@aria-label="Choose theme"]]')->length);
            $this->assertSame(1, $xpath->query('//header//*[@data-flux-dropdown][.//button[@aria-label="Choose theme"]]/following-sibling::*[1][@aria-label="Profile settings"]')->length);
            $trigger = $xpath->query('//header//button[@aria-label="Choose theme"]');
            $this->assertSame(1, $trigger->length);
            $this->assertTrue($trigger->item(0)->hasAttribute('x-bind:aria-label'));
            $this->assertFalse($trigger->item(0)->hasAttribute('disabled'));
            $this->assertSame(1, $xpath->query('//header//*[@data-flux-menu][@aria-label="Theme"]')->length);
            $groups = $xpath->query('//header//*[@data-flux-menu-radio-group][@x-model="$flux.appearance"]');
            $this->assertSame(1, $groups->length);

            $options = $xpath->query('.//*[@data-flux-menu-radio]', $groups->item(0));
            $values = [];
            foreach ($options as $option) {
                $values[$option->getAttribute('value')] = trim($option->textContent);
            }
            $this->assertSame(['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'], $values);
            $this->assertSame(0, $xpath->query('//a[@href="'.route('settings.appearance').'"]')->length);
            $this->assertSame(0, $xpath->query('//nav//*[normalize-space(.)="Appearance"]')->length);
            $this->assertGreaterThan(0, $xpath->query('//a[@href="'.route('settings.profile').'"]')->length);
            $this->assertGreaterThan(0, $xpath->query('//a[@href="'.route('settings.password').'"]')->length);
            $this->assertSame(1, $xpath->query('//form[@action="'.route('logout').'"]')->length);
        }
    }

    public function test_guests_must_log_in_before_following_the_retired_appearance_url(): void
    {
        $this->get('/settings/appearance')->assertRedirect(route('login'));
    }

    #[DataProvider('roles')]
    public function test_the_retired_appearance_url_redirects_through_the_named_dashboard(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get('/settings/appearance')->assertRedirectToRoute('dashboard');
        $this->get(route('dashboard'))->assertRedirectToRoute($role.'.dashboard');
        $this->get(route($role.'.dashboard'))->assertOk();
    }

    public function test_the_retired_appearance_redirect_preserves_unknown_role_rejection(): void
    {
        $user = User::factory()->make(['role' => 'unknown']);

        $this->actingAs($user)->get('/settings/appearance')->assertRedirectToRoute('dashboard');
        $this->get(route('dashboard'))->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'client' => [User::ROLE_CLIENT],
            'freelancer' => [User::ROLE_FREELANCER],
            'admin' => [User::ROLE_ADMIN],
        ];
    }
}
