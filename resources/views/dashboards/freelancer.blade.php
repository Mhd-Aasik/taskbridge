<x-layouts.app title="Freelancer dashboard">
    <x-dashboard.heading title="Freelancer dashboard" description="Your space to get ready for what comes next." />
    <x-dashboard.welcome :user="$user" action="Update your profile" />

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-6">
            <x-dashboard.panel title="Your work, in one place" description="A home for the opportunities ahead.">
                <x-dashboard.empty-state icon="briefcase" title="Your projects will appear here." description="Project discovery and proposals are not available yet. Keep your account details current while you prepare for your next collaboration." />
            </x-dashboard.panel>

            <x-dashboard.panel title="Build a strong foundation" description="Small steps that make a difference.">
                <div class="grid gap-6 p-6 sm:grid-cols-2">
                    <div>
                        <flux:icon.user-circle class="mb-3 size-6 text-teal-700 dark:text-teal-300" />
                        <h3 class="text-sm font-semibold">Keep your introduction current</h3>
                        <p class="tb-muted mt-2 text-sm leading-6">Check your name and email so your account represents you clearly.</p>
                        <a class="tb-text-link mt-4 inline-flex items-center gap-2 text-sm" href="{{ route('settings.profile') }}" wire:navigate>Review profile <flux:icon.arrow-up-right class="size-4" /></a>
                    </div>
                    <div>
                        <flux:icon.shield-check class="mb-3 size-6 text-teal-700 dark:text-teal-300" />
                        <h3 class="text-sm font-semibold">Protect your place</h3>
                        <p class="tb-muted mt-2 text-sm leading-6">Use a strong, unique password to keep your TaskBridge account secure.</p>
                        <a class="tb-text-link mt-4 inline-flex items-center gap-2 text-sm" href="{{ route('settings.password') }}" wire:navigate>Manage password <flux:icon.arrow-up-right class="size-4" /></a>
                    </div>
                </div>
            </x-dashboard.panel>
        </div>
        <aside aria-label="Your account and preferences" class="min-w-0 space-y-6">
            <x-dashboard.account-card :user="$user" />
            <x-dashboard.account-actions />
        </aside>
    </div>
</x-layouts.app>
