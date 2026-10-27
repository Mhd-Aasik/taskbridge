<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\AccountEmailLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VerificationLoginContinuationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_genuine_email_link_continues_after_login_without_verifying_during_login(string $role): void
    {
        $user = User::factory()->unverified()->create(['role' => $role]);
        $password = $user->password;
        $url = app(AccountEmailLinks::class)->verify($user);
        $this->get($url)->assertRedirectToRoute('login');
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')->assertHasNoErrors()->assertRedirect($url);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse(session()->has('url.intended'));
        $this->get($url)->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame($password, $user->fresh()->password);
        $this->get('/dashboard')->assertRedirectToRoute($role.'.dashboard');
        $this->get(route($role.'.dashboard'))->assertOk();
    }

    public static function roles(): array
    {
        return [['admin'], ['client'], ['freelancer']];
    }

    #[DataProvider('invalidDestinations')]
    public function test_login_discards_every_untrusted_expired_or_mismatched_verification_destination(string $case): void
    {
        $user = User::factory()->unverified()->create(['role' => 'client']);
        $url = app(AccountEmailLinks::class)->verify($user);
        switch ($case) {
            case 'trailing-parenthesis': $url .= ')';
                break;
            case 'fragment': $url .= '#unexpected';
                break;
            case 'extra-query': $url .= '&unexpected=1';
                break;
            case 'expired': $this->travel(61)->minutes();
                break;
            case 'other-account': $url = app(AccountEmailLinks::class)->verify(User::factory()->unverified()->create());
                break;
            case 'changed-email': $user->forceFill(['email' => 'changed@taskbridge.test'])->save();
                break;
            case 'other-role-route': $url = route('admin.dashboard');
                break;
            case 'array': $url = ['invalid'];
                break;
            case 'signed-foreign-host':
                $generator = new UrlGenerator(app('router')->getRoutes(), Request::create('https://outside.taskbridge.test'));
                $generator->setKeyResolver(fn () => config('app.key'));
                $url = $generator->temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
                break;
        }
        $before = $user->fresh()->getAttributes();
        $this->withSession(['url.intended' => $url]);
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')->assertHasNoErrors()->assertRedirect('/verify-email');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertFalse(session()->has('url.intended'));
        $this->get('/client/dashboard')->assertRedirectToRoute('verification.notice');
    }

    public static function invalidDestinations(): array
    {
        return array_map(fn ($case) => [$case], ['trailing-parenthesis', 'fragment', 'extra-query', 'expired', 'other-account', 'changed-email', 'other-role-route', 'array', 'signed-foreign-host']);
    }
}
