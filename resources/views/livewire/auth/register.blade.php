<?php

use App\Actions\Accounts\RegisterByEmail;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $role = User::ROLE_CLIENT;

    public function register(): void
    {
        app(RegisterByEmail::class)->begin($this->only('name', 'email', 'role'), (string) request()->ip());
        session()->flash('registration-status', RegisterByEmail::STATUS);
    }

    public function resend(): void
    {
        app(RegisterByEmail::class)->resend($this->email, (string) request()->ip());
        session()->flash('registration-status', RegisterByEmail::STATUS);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Join TaskBridge" description="Enter your details. We will email a secure link to choose your password and verify your address." />
    @if (session('registration-status'))<p role="status" class="text-sm">{{ session('registration-status') }}</p>@endif
    <form wire:submit="register" class="flex flex-col gap-6">
        <flux:input wire:model="name" label="Name" type="text" name="name" required maxlength="255" autocomplete="name" />
        <flux:input wire:model="email" label="Email address" type="email" name="email" required maxlength="255" autocomplete="email" />
        <flux:select wire:model="role" label="Account type" name="role">
            <option value="client">Client</option>
            <option value="freelancer">Freelancer</option>
        </flux:select>
        <flux:button type="submit" variant="primary">Email my setup link</flux:button>
    </form>
    <flux:button type="button" wire:click="resend">Resend setup email</flux:button>
    <p class="text-sm">Already have an account? <x-text-link href="{{ route('login') }}" wire:navigate>Log in</x-text-link></p>
</div>
