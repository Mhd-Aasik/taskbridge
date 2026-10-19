<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('restrictedRoutes')]
    public function test_guests_are_redirected_to_login(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[DataProvider('restrictedRoutes')]
    public function test_json_guests_are_unauthenticated(string $route): void
    {
        $this->getJson(route($route))->assertUnauthorized();
    }

    #[DataProvider('roleAccessMatrix')]
    public function test_users_can_only_access_the_dashboard_for_their_role(string $role, string $route, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get(route($route));

        if ($allowed) {
            $response->assertOk()->assertViewIs('dashboard');
        } else {
            $response->assertForbidden();
        }
    }

    #[DataProvider('roles')]
    public function test_all_roles_can_still_access_the_shared_dashboard(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    #[DataProvider('roles')]
    public function test_role_middleware_accepts_multiple_allowed_roles(string $role): void
    {
        Route::get('/role-access-test', fn () => response('Allowed'))
            ->middleware(['web', 'auth', 'role:'.User::ROLE_CLIENT.','.User::ROLE_FREELANCER]);

        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get('/role-access-test');

        if ($role === User::ROLE_ADMIN) {
            $response->assertForbidden();
        } else {
            $response->assertOk()->assertSee('Allowed');
        }
    }

    public function test_role_middleware_denies_access_when_no_roles_are_configured(): void
    {
        Route::get('/role-access-test', fn () => response('Allowed'))
            ->middleware(['web', 'auth', 'role']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/role-access-test')
            ->assertForbidden();
    }

    public static function restrictedRoutes(): array
    {
        return [
            'client dashboard' => ['client.dashboard'],
            'freelancer dashboard' => ['freelancer.dashboard'],
            'admin dashboard' => ['admin.dashboard'],
        ];
    }

    public static function roles(): array
    {
        return [
            'client' => [User::ROLE_CLIENT],
            'freelancer' => [User::ROLE_FREELANCER],
            'admin' => [User::ROLE_ADMIN],
        ];
    }

    public static function roleAccessMatrix(): array
    {
        $cases = [];

        foreach (self::roles() as [$role]) {
            foreach (self::roles() as [$dashboardRole]) {
                $cases[$role.' visiting '.$dashboardRole.' dashboard'] = [
                    $role,
                    $dashboardRole.'.dashboard',
                    $role === $dashboardRole,
                ];
            }
        }

        return $cases;
    }
}
