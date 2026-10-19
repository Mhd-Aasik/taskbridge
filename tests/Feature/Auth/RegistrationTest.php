<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Account type');
    }

    public function test_clients_can_register(): void
    {
        Volt::test('auth.register')
            ->set('name', 'Test Client')
            ->set('email', 'client@example.com')
            ->set('role', User::ROLE_CLIENT)
            ->set('password', 'TestPassword123!')
            ->set('password_confirmation', 'TestPassword123!')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'email' => 'client@example.com',
            'role' => User::ROLE_CLIENT,
        ]);
    }

    public function test_freelancers_can_register(): void
    {
        Volt::test('auth.register')
            ->set('name', 'Test Freelancer')
            ->set('email', 'freelancer@example.com')
            ->set('role', User::ROLE_FREELANCER)
            ->set('password', 'TestPassword123!')
            ->set('password_confirmation', 'TestPassword123!')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'email' => 'freelancer@example.com',
            'role' => User::ROLE_FREELANCER,
        ]);
    }

    public function test_admin_registration_is_rejected(): void
    {
        Volt::test('auth.register')
            ->set('name', 'Attempted Admin')
            ->set('email', 'admin-attempt@example.com')
            ->set('role', User::ROLE_ADMIN)
            ->set('password', 'TestPassword123!')
            ->set('password_confirmation', 'TestPassword123!')
            ->call('register')
            ->assertHasErrors(['role' => 'in']);

        $this->assertGuest();

        $this->assertDatabaseMissing('users', [
            'email' => 'admin-attempt@example.com',
        ]);
    }
}