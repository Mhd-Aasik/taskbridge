@props(['title', 'description'])

<div class="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        <p class="tb-eyebrow mb-2">Overview</p>
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $title }}</h1>
        <p class="tb-muted mt-2 text-sm leading-6">{{ $description }}</p>
    </div>
    <flux:button :href="route('settings.profile')" icon="user-circle" class="self-start" wire:navigate>Account settings</flux:button>
</div>
