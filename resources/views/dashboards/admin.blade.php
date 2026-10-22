<x-layouts.app title="Admin dashboard">
    <x-dashboard.heading title="Admin dashboard" description="A clear view of the people on TaskBridge." />
    <x-dashboard.welcome :user="$user" action="Review your account" />

    <section aria-label="Registered account totals" class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (['total' => ['Registered accounts', 'users'], 'clients' => ['Clients', 'briefcase'], 'freelancers' => ['Freelancers', 'user-circle'], 'admins' => ['Administrators', 'shield-check']] as $key => [$label, $icon])
            <div class="tb-panel p-6">
                <div class="mb-5 flex items-center justify-between gap-3"><p class="tb-muted text-sm">{{ $label }}</p><flux:icon :name="$icon" class="size-5 text-teal-700 dark:text-teal-300" /></div>
                <p class="text-3xl font-semibold tracking-tight" data-testid="account-count-{{ $key }}">{{ number_format($accountCounts[$key]) }}</p>
                <p class="tb-muted mt-2 text-xs">{{ $key === 'total' ? 'All account roles' : 'Registered '.strtolower($label) }}</p>
            </div>
        @endforeach
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-6">
            <x-dashboard.panel title="Account administration" description="Manage Client and Freelancer access while preserving their work and history."><div class="space-y-4 p-5 sm:p-6"><p class="tb-muted">{{ (int) $accountStatusCounts->active }} active · {{ (int) $accountStatusCounts->suspended }} suspended</p><flux:button :href="route('admin.accounts.index')" variant="primary" wire:navigate>Manage accounts</flux:button></div></x-dashboard.panel>
            <x-dashboard.panel title="Project categories" description="Manage the reference catalogue without changing existing project history.">
                <p class="text-3xl font-semibold" data-testid="category-total">{{ (int) $categoryCounts->total }}</p>
                <p class="tb-muted mt-2 mb-5 text-sm">{{ (int) $categoryCounts->active }} active · {{ (int) $categoryCounts->total - (int) $categoryCounts->active }} inactive</p>
                <flux:button :href="route('admin.categories.index')" variant="primary" wire:navigate>Manage categories</flux:button>
            </x-dashboard.panel>
            <x-dashboard.panel title="Private dispute review" description="Review cases raised by assigned project participants.">
                <p class="text-3xl font-semibold" data-testid="open-dispute-count">{{ number_format($openDisputeCount) }}</p>
                <p class="tb-muted mt-2 mb-5 text-sm">{{ $openDisputeCount === 1 ? 'Open case awaiting review' : 'Open cases awaiting review' }}. Decisions close the case without changing project terms or moving money.</p>
                <flux:button :href="route('admin.disputes.index')" variant="primary" wire:navigate>Review dispute cases</flux:button>
            </x-dashboard.panel>
            <div class="tb-info-notice rounded-xl border border-teal-200 bg-teal-50 p-5 dark:border-teal-900 dark:bg-teal-950/40">
                <div class="flex items-start gap-3"><flux:icon.lock-closed class="mt-0.5 size-5 shrink-0 text-teal-800 dark:text-teal-300" /><div><h2 class="text-sm font-semibold text-teal-950 dark:text-teal-200">An administrator-only overview</h2><p class="mt-1 text-sm leading-6 text-teal-900 dark:text-teal-200/80">Account totals are available only to administrators. Client and freelancer workspaces remain separate and protected.</p></div></div>
            </div>
        </div>
        <aside aria-label="Your account and preferences" class="min-w-0 space-y-6">
            <x-dashboard.account-card :user="$user" />
            <x-dashboard.account-actions />
        </aside>
    </div>
</x-layouts.app>
