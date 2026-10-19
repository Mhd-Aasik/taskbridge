<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $role = User::ROLE_CLIENT;
    public string $password = '';
    public string $password_confirmation = '';

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:' . User::class,
            ],
            'role' => [
                'required',
                'string',
                Rule::in([
                    User::ROLE_CLIENT,
                    User::ROLE_FREELANCER,
                ]),
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                Rules\Password::defaults(),
            ],
        ]);

        $user = new User();
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->password = Hash::make($validated['password']);
        $user->role = $validated['role'];
        $user->save();

        event(new Registered($user));

        Auth::login($user);
        session()->regenerate();

        $this->redirect(
            route('dashboard', absolute: false),
            navigate: true,
        );
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        title="Join TaskBridge"
        description="Create your client or freelancer account"
    />

    <x-auth-session-status
        class="text-center"
        :status="session('status')"
    />

    <form wire:submit="register" class="flex flex-col gap-6">
        <flux:input
            wire:model="name"
            id="name"
            label="Name"
            type="text"
            name="name"
            required
            autofocus
            autocomplete="name"
            placeholder="Full name"
        />

        <flux:input
            wire:model="email"
            id="email"
            label="Email address"
            type="email"
            name="email"
            required
            autocomplete="email"
            placeholder="email@example.com"
        />

        <flux:select
            wire:model="role"
            id="role"
            name="role"
            label="Account type"
            required
        >
            <flux:select.option value="client">
                Client — I want to hire freelancers
            </flux:select.option>

            <flux:select.option value="freelancer">
                Freelancer — I want to find projects
            </flux:select.option>
        </flux:select>

        <flux:input
            wire:model="password"
            id="password"
            label="Password"
            type="password"
            name="password"
            required
            autocomplete="new-password"
            placeholder="Password"
        />

        <flux:input
            wire:model="password_confirmation"
            id="password_confirmation"
            label="Confirm password"
            type="password"
            name="password_confirmation"
            required
            autocomplete="new-password"
            placeholder="Confirm password"
        />

        <flux:button type="submit" variant="primary" class="w-full">
            Create account
        </flux:button>
    </form>

    <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
        Already have an account?
        <x-text-link href="{{ route('login') }}">Log in</x-text-link>
    </div>
</div>