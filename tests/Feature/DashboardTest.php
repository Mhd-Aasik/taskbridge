<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsUnknownRoleAccount;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsUnknownRoleAccount, RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect('/login');
    }

    #[DataProvider('dashboardRoles')]
    public function test_authenticated_users_are_redirected_to_their_role_dashboard(string $role, string $dashboard): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect($dashboard);

        $this->get($dashboard)->assertOk();
    }

    public function test_unknown_roles_are_forbidden(): void
    {
        $user = $this->unknownRoleAccount();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();

        foreach (self::dashboardRoles() as [$role, $dashboard]) {
            $this->get($dashboard)->assertForbidden();
        }
    }

    public static function dashboardRoles(): array
    {
        return [
            'client' => [User::ROLE_CLIENT, '/client/dashboard'],
            'freelancer' => [User::ROLE_FREELANCER, '/freelancer/dashboard'],
            'admin' => [User::ROLE_ADMIN, '/admin/dashboard'],
        ];
    }
}
