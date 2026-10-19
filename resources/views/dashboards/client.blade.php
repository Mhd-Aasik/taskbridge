<x-layouts.app title="Client dashboard">
    <x-dashboard.heading title="Client dashboard" description="A little structure for your next big idea." />
    <x-dashboard.welcome :user="$user" />

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-6">
            <x-dashboard.panel title="Your project workspace" description="From the first brief to the final handover.">
                <x-dashboard.empty-state icon="folder-plus" title="Your projects will appear here." description="Project posting is not available yet. Keep your account details current while you prepare for your next collaboration." />
            </x-dashboard.panel>

            <x-dashboard.panel title="Before your next collaboration" description="A good brief makes room for great work.">
                <ol class="divide-y divide-slate-100 px-6 dark:divide-slate-700/60">
                    @foreach (['Define the outcome' => 'Write down what success looks like and who the work is for.', 'Gather your references' => 'Keep useful examples, inspiration, and existing assets together.', 'Think through the scope' => 'Consider the deliverables, timing, and decisions a collaborator will need.'] as $heading => $description)
                        <li class="flex gap-4 py-5"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $loop->iteration }}</span><div><h3 class="text-sm font-semibold">{{ $heading }}</h3><p class="tb-muted mt-1 text-sm leading-6">{{ $description }}</p></div></li>
                    @endforeach
                </ol>
            </x-dashboard.panel>
        </div>
        <aside aria-label="Your account and preferences" class="min-w-0 space-y-6">
            <x-dashboard.account-card :user="$user" />
            <x-dashboard.account-actions />
        </aside>
    </div>
</x-layouts.app>
