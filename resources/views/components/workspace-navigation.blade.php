<nav aria-label="Workspace navigation" class="space-y-6">
    <div>
        <p class="mb-3 px-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Workspace</p>
        <flux:navlist>
            <flux:navlist.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard', '*.dashboard')" wire:navigate>Dashboard</flux:navlist.item>
            @if (auth()->user()->isClient())
                <flux:navlist.item icon="folder" :href="route('client.projects.index')" :current="request()->routeIs('client.projects.*', 'projects.plan')" wire:navigate>Projects</flux:navlist.item>
            @endif
            @if (auth()->user()->isFreelancer())
                <flux:navlist.item icon="magnifying-glass" :href="route('freelancer.projects.index')" :current="request()->routeIs('freelancer.projects.*', 'freelancer.proposals.create')" wire:navigate>Browse projects</flux:navlist.item>
                <flux:navlist.item icon="document-text" :href="route('freelancer.proposals.index')" :current="request()->routeIs('freelancer.proposals.*') && ! request()->routeIs('freelancer.proposals.create')" wire:navigate>My proposals</flux:navlist.item>
                <flux:navlist.item icon="briefcase" :href="route('freelancer.assignments.index')" :current="request()->routeIs('freelancer.assignments.*', 'projects.plan')" wire:navigate>Assigned projects</flux:navlist.item>
                <flux:navlist.item icon="user-circle" :href="route('freelancer.profile.edit')" :current="request()->routeIs('freelancer.profile.edit')" wire:navigate>Freelancer profile</flux:navlist.item>
            @endif
            @if (auth()->user()->isClient() || auth()->user()->isFreelancer())
                <flux:navlist.item icon="chat-bubble-left-right" :href="route('disputes.index')" :current="request()->routeIs('disputes.*', 'projects.disputes.*')" wire:navigate>Disputes</flux:navlist.item>
            @endif
            @if (auth()->user()->isAdmin())
                <flux:navlist.item icon="chat-bubble-left-right" :href="route('admin.disputes.index')" :current="request()->routeIs('admin.disputes.*')" wire:navigate>Dispute cases</flux:navlist.item>
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
