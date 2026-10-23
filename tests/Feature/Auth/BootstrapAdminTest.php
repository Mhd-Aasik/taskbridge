<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\BootstrapAdmin;
use App\Console\Commands\BootstrapAdminCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class BootstrapAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Isolated-Admin-734!';

    protected function setUp(): void
    {
        parent::setUp();
        // Simulate a terminal only in these prompt tests; actual piped CLI is checked separately.
        $this->app->bind(BootstrapAdminCommand::class, fn () => new class extends BootstrapAdminCommand
        {
            protected function hasInteractiveTerminal(): bool
            {
                return true;
            }
        });
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['name' => 'First Admin', 'email' => 'admin@taskbridge.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], $overrides);
    }

    public function test_console_creates_only_the_first_admin_with_hashed_password_and_optional_verification(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $freelancer = User::factory()->create(['role' => 'freelancer']);
        $before = [$client->fresh()->getAttributes(), $freelancer->fresh()->getAttributes()];
        $this->artisan('taskbridge:bootstrap-admin')
            ->expectsQuestion('Name', ' First Admin ')->expectsQuestion('Email', 'ADMIN@taskbridge.test')
            ->expectsQuestion('Password', self::PASSWORD)->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsConfirmation('Create this first Admin account?', 'yes')->assertSuccessful();
        $admin = User::where('role', 'admin')->sole();
        $this->assertSame('First Admin', $admin->name);
        $this->assertSame('admin@taskbridge.test', $admin->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertNull($admin->email_verified_at);
        $this->assertNull($admin->suspended_at);
        $this->assertNull($admin->remember_token);
        $this->assertNull($admin->avatar_path);
        $this->assertSame(0, $admin->access_version);
        $this->assertSame($before, [$client->fresh()->getAttributes(), $freelancer->fresh()->getAttributes()]);
        $this->assertDatabaseCount('users', 3);
        Volt::test('auth.login')->set('email', $admin->email)->set('password', self::PASSWORD)->call('login')->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->get(route('dashboard'))->assertRedirectToRoute('admin.dashboard');
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('client.dashboard'))->assertForbidden();
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_never_creates_an_account(array $changes, string $field): void
    {
        try {
            app(BootstrapAdmin::class)->create($this->input($changes));
            $this->fail('Invalid bootstrap accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'blank name' => [['name' => '   '], 'name'],
            'long name' => [['name' => str_repeat('x', 256)], 'name'],
            'terminal controls' => [['name' => "Bad\033[31m"], 'name'],
            'email format' => [['email' => 'invalid'], 'email'],
            'short password' => [['password' => 'Short-7!'], 'password'],
            'weak password' => [['password' => 'onlylowercaseletters'], 'password'],
            'mismatch' => [['password_confirmation' => 'Different-Admin-734!'], 'password'],
            'bcrypt byte limit' => [['password' => str_repeat('é', 40).'A9!'], 'password'],
        ];
    }

    public function test_duplicate_email_preserves_existing_account_and_role(): void
    {
        $user = User::factory()->create(['email' => 'ADMIN@taskbridge.test', 'role' => 'freelancer']);
        $before = $user->fresh()->getAttributes();
        try {
            app(BootstrapAdmin::class)->create($this->input());
            $this->fail('Existing account was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('email', $error->errors());
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_unavailable_admin_also_refuses_without_prompting(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'suspended_at' => now()]);
        $before = $admin->fresh()->getAttributes();
        $this->artisan('taskbridge:bootstrap-admin')->expectsOutputToContain('An Admin account already exists')->assertFailed();
        try {
            app(BootstrapAdmin::class)->create($this->input());
            $this->fail('Existing Admin ignored.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('admin', $error->errors());
        }
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_active_admin_refuses_without_creating_another(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $before = $admin->fresh()->getAttributes();
        $this->artisan('taskbridge:bootstrap-admin')->expectsOutputToContain('An Admin account already exists')->assertFailed();
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_cancellation_and_no_interaction_do_not_create_an_account(): void
    {
        $this->artisan('taskbridge:bootstrap-admin', ['--no-interaction' => true])->expectsOutputToContain('requires an interactive terminal')->assertFailed();
        $this->artisan('taskbridge:bootstrap-admin')
            ->expectsQuestion('Name', 'First Admin')->expectsQuestion('Email', 'admin@taskbridge.test')
            ->expectsQuestion('Password', self::PASSWORD)->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsConfirmation('Create this first Admin account?', 'no')->expectsOutputToContain('Cancelled')->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_prompt_validation_fails_without_confirmation_or_creating_records(): void
    {
        $this->artisan('taskbridge:bootstrap-admin')
            ->expectsQuestion('Name', 'First Admin')->expectsQuestion('Email', 'invalid')
            ->expectsQuestion('Password', self::PASSWORD)->expectsQuestion('Confirm password', 'mismatch')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_creation_failure_rolls_back_and_protected_input_is_ignored(): void
    {
        $listener = function (User $user): void {
            if ($user->isAdmin()) {
                throw new RuntimeException('Injected post-insert failure');
            }
        };
        $events = User::getEventDispatcher();
        $copy = clone $events;
        try {
            User::created($listener);
            try {
                app(BootstrapAdmin::class)->create($this->input());
                $this->fail('Injected failure swallowed.');
            } catch (RuntimeException $error) {
                $this->assertSame('Injected post-insert failure', $error->getMessage());
            }
        } finally {
            // Restore listeners without removing application observers for later tests.
            User::setEventDispatcher($copy);
            Event::swap($copy);
        }
        $this->assertDatabaseCount('users', 0);
        $admin = app(BootstrapAdmin::class)->create($this->input(['id' => 999, 'role' => 'client', 'access_version' => 9, 'email_verified_at' => now(), 'suspended_at' => now()]));
        $this->assertSame('admin', $admin->role);
        $this->assertNotSame(999, $admin->id);
        $this->assertNull($admin->email_verified_at);
        $this->assertNull($admin->suspended_at);
        $this->assertSame(0, $admin->access_version);
    }

    public function test_actual_command_definition_accepts_no_account_or_password_arguments(): void
    {
        $command = new BootstrapAdminCommand;
        $command->setLaravel($this->app);
        $this->assertSame([], $command->getDefinition()->getArguments());
        foreach (['password', 'password_confirmation', 'email', 'name', 'force'] as $name) {
            $this->assertFalse($command->getDefinition()->hasOption($name));
        }
        $this->assertSame(1, $command->run(new ArrayInput([]), new BufferedOutput));
    }
}
