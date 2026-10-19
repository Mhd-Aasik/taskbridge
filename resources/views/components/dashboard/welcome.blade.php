@props(['user', 'action' => 'Review your profile'])

<section aria-label="Welcome" class="relative mb-7 overflow-hidden rounded-2xl border border-slate-700 bg-[#152b43] p-6 text-white sm:p-8">
    <div class="pointer-events-none absolute -right-8 -top-16 size-64 rounded-full border-[40px] border-white/[0.025]" aria-hidden="true"></div>
    <div class="relative flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
        <div class="min-w-0 max-w-2xl">
            <p class="mb-3 flex items-center gap-2 text-xs font-medium text-teal-200"><span class="size-1.5 rounded-full bg-teal-300" aria-hidden="true"></span>{{ ucfirst($user->role) }} workspace</p>
            <h2 class="text-2xl font-semibold leading-tight tracking-tight [overflow-wrap:anywhere] sm:text-3xl">Welcome back, {{ $user->name }}.</h2>
            <p class="mt-3 max-w-xl text-sm leading-6 text-slate-300">Keep your profile up to date so collaborators know who they’re working with.</p>
        </div>
        <flux:button :href="route('settings.profile')" variant="primary" icon:trailing="arrow-up-right" class="shrink-0 self-start md:self-center" wire:navigate>{{ $action }}</flux:button>
    </div>
</section>
