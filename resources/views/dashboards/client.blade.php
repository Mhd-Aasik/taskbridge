<x-layouts.app title="Client dashboard">
    <x-dashboard.heading title="Client dashboard" description="A little structure for your next big idea." />
    <x-dashboard.welcome :user="$user" />

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold">Your project workspace</h2>
                    <p class="tb-muted mt-1 text-sm">{{ $projectCount }} {{ Str::plural('project', $projectCount) }}. {{ $draftCount }} {{ Str::plural('draft', $draftCount) }}, {{ $openCount }} open. Drafts are private.</p>
                    <p class="tb-muted mt-2 text-sm">{{ $receivedProposalCount }} {{ Str::plural('proposal', $receivedProposalCount) }} received across your projects.</p>
                    <p class="tb-muted mt-2 text-sm">{{ $assignedProjectCount }} {{ Str::plural('project', $assignedProjectCount) }} assigned to freelancers.</p>
                </div>
                <flux:button :href="route('client.projects.create')" variant="primary" icon="plus" class="self-start" wire:navigate>Create project</flux:button>
            </div>
            @forelse ($recentProjects as $project)
                <x-projects.card :project="$project" />
                <x-projects.completion :record="$project" />
                @if ($project->assignment)
                    <x-payments.summary :project="$project" :assignment="$project->assignment" />
                @endif
            @empty
                <x-dashboard.panel title="Start your next project" description="Capture the details at your own pace.">
                    <x-dashboard.empty-state icon="folder-plus" title="Your projects will appear here." description="Create a private draft with your brief, budget, and deadline. Review the saved details and publish when you are ready." />
                </x-dashboard.panel>
            @endforelse
            <p class="tb-muted">{{ $completedProjectCount }} completed {{ Str::plural('project', $completedProjectCount) }}. Completion does not confirm payment.</p>
            <flux:button :href="route('client.projects.index')" wire:navigate>View all projects</flux:button>

            <x-dashboard.panel title="Before your next collaboration" description="A good brief makes room for great work.">
                <ol class="divide-y divide-slate-100 px-6 dark:divide-slate-700/60">
                    @foreach (['Define the outcome' => 'Write down what success looks like and who the work is for.', 'Gather your references' => 'Keep useful examples, inspiration, and existing assets together.', 'Think through the scope' => 'Consider the deliverables, timing, and decisions a collaborator will need.'] as $heading => $description)
                        <li class="flex gap-4 py-5"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $loop->iteration }}</span><div><h3 class="text-sm font-semibold">{{ $heading }}</h3><p class="tb-muted mt-1 text-sm leading-6">{{ $description }}</p></div></li>
                    @endforeach
                </ol>
            </x-dashboard.panel>
        </div>
        <aside aria-label="Your account and preferences" class="min-w-0 space-y-6">
            <x-dashboard.account-card :user="$user" :reputation="$reputation" />
            <x-dashboard.account-actions />
            <x-dashboard.panel title="Find your next collaborator"><div class="space-y-4 p-5 sm:p-6"><p class="tb-muted leading-7">Explore professional profiles shared with Clients, then post a project to receive proposals.</p><flux:button :href="route('client.freelancers.index')" wire:navigate>Find freelancers</flux:button></div></x-dashboard.panel>
        </aside>
    </div>
</x-layouts.app>
