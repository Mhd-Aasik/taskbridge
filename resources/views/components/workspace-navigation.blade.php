<nav aria-label="Workspace navigation" class="space-y-6">
    <div>
        <p class="mb-3 px-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Workspace</p>
        <flux:navlist>
            <flux:navlist.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard', '*.dashboard')" wire:navigate>Dashboard</flux:navlist.item>
            @if (auth()->user()->isFreelancer())
                <flux:navlist.item icon="user-circle" :href="route('freelancer.profile.edit')" :current="request()->routeIs('freelancer.profile.edit')" wire:navigate>Freelancer profile</flux:navlist.item>
            @endif
        </flux:navlist>
    </div>
    <div>
        <p class="mb-3 px-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Your account</p>
        <flux:navlist>
            <flux:navlist.item icon="user-circle" :href="route('settings.profile')" :current="request()->routeIs('settings.profile')" wire:navigate>Profile</flux:navlist.item>
            <flux:navlist.item icon="lock-closed" :href="route('settings.password')" :current="request()->routeIs('settings.password')" wire:navigate>Password</flux:navlist.item>
        </flux:navlist>
    </div>
</nav>
