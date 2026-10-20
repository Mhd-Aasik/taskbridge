<x-layouts.app title="Assigned projects">
    <div class="tb-form-editor mx-auto max-w-5xl space-y-6">
        <div>
            <p class="tb-eyebrow mb-2">Freelancer workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Assigned projects</h1>
            <p class="tb-muted mt-2">Your selected projects and the terms agreed when your proposals were accepted.</p>
        </div>

        @if ($assignments->isEmpty())
            <x-dashboard.panel title="Your assigned work">
                <x-dashboard.empty-state icon="briefcase" :title="$assignments->total() ? 'No assignments on this page.' : 'No assigned projects yet.'" :description="$assignments->total() ? 'Use the page links below to return to your assigned projects.' : 'When a client accepts your proposal, the project and agreed terms will appear here.'" />
                <div class="px-5 pb-5 sm:px-6">
                    @if ($assignments->total())
                        {{ $assignments->links() }}
                    @else
                        <flux:button :href="route('freelancer.projects.index')" wire:navigate>Browse projects</flux:button>
                    @endif
                </div>
            </x-dashboard.panel>
        @else
            <p role="status" class="tb-muted text-sm">{{ $assignments->total() }} assigned {{ Str::plural('project', $assignments->total()) }}.</p>
            <div class="space-y-4">
                @foreach ($assignments as $assignment)
                    <article class="tb-panel p-5 sm:p-6">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <h2 class="break-words text-lg font-semibold">{{ $assignment->project->title }}</h2>
                                <p class="tb-muted mt-1 break-words">Client: {{ $assignment->project->client->name }}</p>
                                <p class="tb-muted mt-1 text-sm">Status: {{ Str::headline($assignment->project->status->value) }}</p>
                            </div>
                            <flux:button :href="route('freelancer.assignments.show', $assignment)" :aria-label="'View assigned project '.$assignment->project->title" class="self-start shrink-0" wire:navigate>View assigned project</flux:button>
                        </div>
                        <x-assignments.terms :assignment="$assignment" class="mt-5 border-t border-slate-100 pt-5 dark:border-slate-700" />
                    </article>
                @endforeach
            </div>
            {{ $assignments->links() }}
        @endif
    </div>
</x-layouts.app>
