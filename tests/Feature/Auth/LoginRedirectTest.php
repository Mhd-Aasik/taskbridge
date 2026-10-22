<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsUnknownRoleAccount;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use BuildsUnknownRoleAccount, RefreshDatabase;

    #[DataProvider('crossRoleDestinations')]
    public function test_cross_role_intended_destinations_are_consumed_and_login_uses_the_dashboard_resolver(string $role, string $intendedRoute): void
    {
        $user = User::factory()->create(['role' => $role]);
        $intended = route($intendedRoute);
        $this->get($intended)->assertRedirect(route('login'))->assertSessionHas('url.intended', $intended);
        session()->put('unrelated.preference', 'keep-this-value');
        $beforeSessionId = session()->getId();

        $component = Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($component->effects['redirectUsingNavigate'] ?? false);
        $this->assertFalse(session()->has('url.intended'));
        $this->assertSame('keep-this-value', session('unrelated.preference'));
        $this->assertNotSame($beforeSessionId, session()->getId());
        $this->assertSame(['id' => $user->id, 'version' => 0], session(AccountAccess::SESSION_KEY));
        $this->get(route('dashboard'))->assertRedirectToRoute($role.'.dashboard');
        $this->get(route($role.'.dashboard'))->assertOk();
        $this->get($intended)->assertForbidden();
    }

    #[DataProvider('staleDestinations')]
    public function test_external_and_stale_session_destinations_never_become_the_login_redirect(mixed $intended): void
    {
        $user = User::factory()->create(['role' => 'client']);
        session()->put('url.intended', $intended);

        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertFalse(session()->has('url.intended'));
        $this->get(route('dashboard'))->assertRedirectToRoute('client.dashboard');
    }

    public function test_freelancer_logout_then_client_login_does_not_reuse_freelancer_navigation(): void
    {
        $freelancer = User::factory()->create(['role' => 'freelancer', 'remember_token' => 'freelancer-token']);
        $client = User::factory()->create(['role' => 'client', 'remember_token' => 'client-token']);
        Volt::test('auth.login')->set('email', $freelancer->email)->set('password', 'password')->set('remember', true)->call('login')->assertHasNoErrors();
        $this->get(route('dashboard'))->assertRedirectToRoute('freelancer.dashboard');
        session()->put('url.intended', route('freelancer.projects.index'));
        $oldSessionId = session()->getId();

        $this->post(route('logout'))->assertRedirect('/')->assertSessionMissing('url.intended')->assertSessionMissing(AccountAccess::SESSION_KEY);
        $this->assertGuest();
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame('freelancer-token', $freelancer->fresh()->remember_token);

        // A stale navigation link can legitimately set a new intended URL after logout.
        $this->get(route('freelancer.projects.index'), ['X-Livewire-Navigate' => 'true'])->assertRedirect(route('login'))->assertSessionHas('url.intended', route('freelancer.projects.index'));
        Volt::test('auth.login')->set('email', $client->email)->set('password', 'password')->set('remember', true)->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($client);
        $this->assertSame('client-token', $client->fresh()->remember_token);
        $this->assertFalse(session()->has('url.intended'));
        $this->get(route('dashboard'))->assertRedirectToRoute('client.dashboard');
        $this->get(route('client.dashboard'))->assertOk();
        $this->get(route('freelancer.projects.index'))->assertForbidden();
    }

    public function test_database_session_login_preserves_other_accounts_sessions_and_remember_tokens(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $client = User::factory()->create(['role' => 'client', 'remember_token' => 'client-existing-token']);
        $other = User::factory()->create(['role' => 'freelancer', 'remember_token' => 'other-existing-token']);
        foreach (['other-session-one', 'other-session-two'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $other->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
        $before = DB::table('sessions')->where('user_id', $other->id)->orderBy('id')->get()->toJson();
        $this->get(route('freelancer.projects.index'))->assertRedirect(route('login'));
        $this->assertInstanceOf(DatabaseSessionHandler::class, session()->getHandler());

        Volt::test('auth.login')->set('email', $client->email)->set('password', 'password')->set('remember', true)->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame($before, DB::table('sessions')->where('user_id', $other->id)->orderBy('id')->get()->toJson());
        $this->assertSame('other-existing-token', $other->fresh()->remember_token);
        $this->assertSame('client-existing-token', $client->fresh()->remember_token);
        $this->assertAuthenticatedAs($client);
        $this->assertFalse(session()->has('url.intended'));
    }

    #[DataProvider('crossRoleDestinations')]
    public function test_direct_wrong_role_access_still_returns_403(string $role, string $forbiddenRoute): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get(route($forbiddenRoute))->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('roles')]
    public function test_suspended_accounts_cannot_login_or_receive_a_dashboard_redirect(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'suspended_at' => now('UTC'), 'access_version' => 1,
            'suspension_reason' => 'PRIVATE-ADMIN-REASON', 'remember_token' => 'suspended-token']);
        session()->put('url.intended', route('dashboard'));

        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->set('remember', true)->call('login')
            ->assertHasErrors(['email'])->assertNoRedirect()->assertSeeText(AccountAccess::UNAVAILABLE)->assertDontSee('PRIVATE-ADMIN-REASON');

        $this->assertGuest();
        $this->assertNull(Auth::getProvider()->retrieveByToken($user->id, 'suspended-token'));
        $this->assertSame('suspended-token', $user->fresh()->remember_token);
        $this->assertSame(1, $user->fresh()->access_version);
        $this->assertNotNull($user->fresh()->suspended_at);
    }

    public function test_invalid_password_does_not_login_regenerate_tokens_or_consume_intended_state(): void
    {
        $user = User::factory()->create(['role' => 'client', 'remember_token' => 'unchanged-token']);
        $intended = route('freelancer.projects.index');
        session()->put('url.intended', $intended);

        Volt::test('auth.login')->set('email', $user->email)->set('password', 'incorrect')->call('login')->assertHasErrors(['email'])->assertNoRedirect();

        $this->assertGuest();
        $this->assertSame($intended, session('url.intended'));
        $this->assertSame('unchanged-token', $user->fresh()->remember_token);
    }

    public function test_corrupt_unknown_roles_are_still_rejected_by_the_dashboard_resolver(): void
    {
        $user = $this->unknownRoleAccount();
        session()->put('url.intended', route('client.projects.index'));
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->get(route('dashboard'))->assertForbidden();
    }

    public static function crossRoleDestinations(): array
    {
        return ['Client after Freelancer page' => ['client', 'freelancer.projects.index'],
            'Freelancer after Client page' => ['freelancer', 'client.projects.index'],
            'Admin after Client page' => ['admin', 'client.projects.index']];
    }

    public static function roles(): array
    {
        return ['Client' => ['client'], 'Freelancer' => ['freelancer'], 'Admin' => ['admin']];
    }

    public static function staleDestinations(): array
    {
        return ['external HTTPS' => ['https://outside.example.test/untrusted'], 'protocol relative' => ['//outside.example.test/untrusted'],
            'missing relative page' => ['/retired/project/page?old=1'], 'missing absolute page' => ['http://localhost/retired-project'],
            'login loop' => ['/login'], 'same role page' => ['/client/projects'], 'malformed session value' => [['unexpected' => '/freelancer/projects']]];
    }
}
