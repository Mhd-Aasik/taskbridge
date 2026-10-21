<x-layouts.app title="Freelancer dashboard">
    <x-dashboard.heading title="Freelancer dashboard" description="Find your next collaboration and keep your profile current." />
    <x-dashboard.welcome :user="$user" action="Browse projects" :profile-url="route('freelancer.projects.index')" />

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-6">
            <x-dashboard.panel title="Your assigned projects" description="Agreed work selected by clients.">
                <div class="space-y-4 p-6">
                    <p class="tb-muted">{{ $assignmentCount }} assigned {{ Str::plural('project', $assignmentCount) }}.</p>
                    <p class="tb-muted">{{ $completedAssignmentCount }} completed {{ Str::plural('project', $completedAssignmentCount) }}. Completion does not confirm payment.</p>
                    @forelse ($recentAssignments as $assignment)
                        <div class="flex items-center gap-4">
                            <x-projects.cover :project="$assignment->project" compact />
                            <a href="{{ route('freelancer.assignments.show', $assignment) }}" class="tb-text-link min-w-0 break-words" wire:navigate>{{ $assignment->project->title }} · {{ $assignment->formattedBudget() }}</a>
                        </div>
                        <x-projects.completion :record="$assignment->project" />
                    @empty
                        <p class="tb-muted leading-7">When a client accepts your proposal, your assigned project will appear here.</p>
                    @endforelse
                    <flux:button :href="route('freelancer.assignments.index')" icon="briefcase" wire:navigate>Assigned projects</flux:button>
                </div>
            </x-dashboard.panel>

            <x-dashboard.panel title="Find your next collaboration" description="Explore open projects from TaskBridge clients.">
                <div class="space-y-4 p-6">
                    <p class="tb-muted leading-7">Browse published briefs by category, budget, and deadline. Read the project details to find work that fits your skills.</p>
                    <p class="tb-muted text-sm leading-6">Send a considered proposal, then follow its status in My proposals. Submission does not guarantee acceptance.</p>
                    <flux:button :href="route('freelancer.projects.index')" variant="primary" icon="magnifying-glass" wire:navigate>Browse projects</flux:button>
                    <flux:button :href="route('freelancer.proposals.index')" wire:navigate>My proposals</flux:button>
                </div>
            </x-dashboard.panel>

            <x-dashboard.panel title="Build a strong foundation" description="Small steps that make a difference.">
                <div class="grid gap-6 p-6 sm:grid-cols-2">
                    <div>
                        <flux:icon.user-circle class="mb-3 size-6 text-teal-700 dark:text-teal-300" />
                        <h3 class="text-sm font-semibold">Keep your introduction current</h3>
                        <p class="tb-muted mt-2 text-sm leading-6">Introduce your work, choose your skills, and keep your freelancer profile current.</p>
                        <a class="tb-text-link mt-4 inline-flex items-center gap-2 text-sm" href="{{ route('freelancer.profile.edit') }}" wire:navigate>Review profile <flux:icon.arrow-up-right class="size-4" /></a>
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
