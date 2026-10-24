<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_head_loads_the_stylesheet_once_without_redundant_css_preloads(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client']));
        foreach (['client.dashboard', 'client.projects.create'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $document = new DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new DOMXPath($document);
            $this->assertSame(1, $xpath->query('//head/link[@rel="stylesheet" and contains(@href,"/build/assets/app-")]')->length);
            $this->assertSame(0, $xpath->query('//head/link[@rel="preload" and @as="style"]')->length);
            $this->assertStringNotContainsString('as=style', $response->headers->get('Link', ''));
        }
    }

    public function test_shared_head_has_no_external_font_dependency(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('fonts.bunny.net', false)->assertDontSee('fonts.googleapis.com', false);
    }

    public function test_public_home_uses_taskbridge_branding_and_real_account_destinations(): void
    {
        $this->get(route('home'))->assertOk()->assertSeeText('Bring your next project to life.')
            ->assertSee(route('login'), false)->assertSee(route('register'), false)
            ->assertDontSee('laracasts.com', false)->assertDontSee('cloud.laravel.com', false)
            ->assertDontSee('fonts.bunny.net', false);
    }

    public function test_authenticated_home_links_each_role_to_the_dashboard_resolver(): void
    {
        foreach (['client', 'freelancer', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('home'))
                ->assertOk()->assertSeeText('Open your workspace')->assertSee(route('dashboard'), false)->assertDontSeeText('Get started');
        }
    }
}
