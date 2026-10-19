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
            <x-dashboard.panel title="Workspace administration" description="A foundation for a well-run community.">
                <x-dashboard.empty-state icon="shield-check" title="Clarity before complexity" description="Account management and moderation tools are not available yet. This overview currently provides read-only account totals, so you can understand who is joining the platform." />
            </x-dashboard.panel>
            <div class="rounded-xl border border-teal-200 bg-teal-50 p-5 dark:border-teal-900 dark:bg-teal-950/40">
                <div class="flex items-start gap-3"><flux:icon.lock-closed class="mt-0.5 size-5 shrink-0 text-teal-800 dark:text-teal-300" /><div><h2 class="text-sm font-semibold text-teal-950 dark:text-teal-200">An administrator-only overview</h2><p class="mt-1 text-sm leading-6 text-teal-900 dark:text-teal-200/80">Account totals are available only to administrators. Client and freelancer workspaces remain separate and protected.</p></div></div>
            </div>
        </div>
        <aside aria-label="Your account and preferences" class="min-w-0 space-y-6">
            <x-dashboard.account-card :user="$user" />
            <x-dashboard.account-actions />
        </aside>
    </div>
</x-layouts.app>
