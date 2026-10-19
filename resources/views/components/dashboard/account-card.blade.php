@props(['user'])

<x-dashboard.panel title="Your account" description="The details behind your workspace.">
    <div class="p-6">
        <div class="mb-6 flex min-w-0 items-center gap-3">
            <x-avatar :user="$user" class="size-12 rounded-2xl bg-teal-50 font-semibold text-teal-800 dark:bg-teal-950 dark:text-teal-200" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                <p class="tb-muted mt-1 text-xs">{{ ucfirst($user->role) }} account</p>
            </div>
        </div>
        <dl class="space-y-4 text-sm">
            <div><dt class="tb-eyebrow mb-1">Email address</dt><dd class="break-all leading-6">{{ $user->email }}</dd></div>
            <div><dt class="tb-eyebrow mb-1">Member since</dt><dd class="leading-6">{{ $user->created_at->format('F Y') }}</dd></div>
        </dl>
        <a href="{{ route('settings.profile') }}" class="tb-text-link mt-6 inline-flex items-center gap-2 text-sm" wire:navigate>Edit account details <flux:icon.arrow-up-right class="size-4" /></a>
    </div>
</x-dashboard.panel>
