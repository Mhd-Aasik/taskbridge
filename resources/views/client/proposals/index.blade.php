<x-layouts.app title="Project proposals">
    <div class="tb-form-editor mx-auto max-w-5xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="tb-eyebrow mb-2">Client workspace</p>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Project proposals</h1>
                <div class="mt-4 flex items-start gap-4">
                    <x-projects.cover :project="$project" compact />
                    <div class="min-w-0">
                        <p class="break-words font-medium">{{ $project->title }}</p>
                        <p class="tb-muted mt-1 text-sm">{{ $project->proposals_count }} {{ Str::plural('proposal', $project->proposals_count) }} received for this project.</p>
                    </div>
                </div>
            </div>
            <flux:button :href="route('client.projects.show', $project)" class="self-start shrink-0" icon="arrow-left" wire:navigate>Project review</flux:button>
        </div>

        @if ($project->assignment)
            <x-assignments.client-summary :assignment="$project->assignment" />
        @endif

        <x-dashboard.panel title="Filter proposals">
            <form method="GET" action="{{ route('client.projects.proposals.index', $project) }}" class="space-y-5 p-5 sm:p-6" x-data="{ filtering: false }" x-on:submit="filtering = true" x-on:pageshow.window="filtering = false" x-on:livewire:navigated.window="filtering = false" x-bind:aria-busy="filtering">
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <flux:select name="status" label="Proposal status" :invalid="$filterErrors->has('status')" :error:message="$filterErrors->first('status')">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select name="sort" label="Sort proposals" :invalid="$filterErrors->has('sort')" :error:message="$filterErrors->first('sort')">
                        @foreach ($sorts as $value => $label)
                            <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary" x-bind:disabled="filtering">Filter proposals</flux:button>
                    <flux:button :href="route('client.projects.proposals.index', $project)" variant="ghost" wire:navigate>Clear filters</flux:button>
                    <span x-cloak x-show="filtering" role="status" class="tb-muted text-sm">Filtering proposals...</span>
                </div>
            </form>
        </x-dashboard.panel>

        @if ($filterErrors->any())
            <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-5 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">
                <p class="font-semibold">Check your filters and try again.</p>
                @if ($filterErrors->has('page'))
                    <p class="mt-2">{{ $filterErrors->first('page') }} Use Clear filters to return to the first page.</p>
                @endif
            </div>
        @elseif ($proposals->isEmpty())
            <x-dashboard.panel title="Proposals for your project">
                @if ($proposals->total() > 0)
                    <x-dashboard.empty-state icon="document-text" title="No proposals on this page." description="Use the page links below to return to the available results." />
                    <div class="px-5 pb-5 sm:px-6">{{ $proposals->links() }}</div>
                @else
                    <x-dashboard.empty-state icon="document-text" :title="$project->proposals_count ? 'No proposals match this status.' : 'No proposals yet.'" :description="$project->proposals_count ? 'Choose another status or clear your filters to review the other proposals.' : 'Proposals submitted for this project will appear here.'" />
                @endif
            </x-dashboard.panel>
        @else
            <p class="tb-muted text-sm" role="status">{{ $proposals->total() }} {{ Str::plural('proposal', $proposals->total()) }}{{ $filters['status'] ? ' with this status' : '' }}.</p>
            <div class="space-y-4" aria-label="Project proposal list">
                @foreach ($proposals as $proposal)
                    <article class="tb-panel p-5 sm:p-6">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <h2 class="break-words text-lg font-semibold">{{ $proposal->freelancer->name }}</h2>
                                <x-feedback.reputation :summary="$freelancerReputations[$proposal->freelancer_id]" class="mt-3" />
                                <p class="tb-muted mt-1 text-sm">Status: {{ Str::headline($proposal->status->value) }}</p>
                            </div>
                            <flux:button :href="route('client.projects.proposals.show', [$project, $proposal])" :aria-label="'View proposal from '.$proposal->freelancer->name" class="self-start shrink-0" wire:navigate>View proposal</flux:button>
                        </div>
                        <p class="tb-muted mt-4 whitespace-pre-wrap break-words leading-7">{{ Str::limit($proposal->cover_letter, 220) }}</p>
                        <x-proposals.review-offer :proposal="$proposal" :project="$project" class="mt-5 border-t border-slate-100 pt-5 dark:border-slate-700" />
                    </article>
                @endforeach
            </div>
            {{ $proposals->links() }}
        @endif
    </div>
</x-layouts.app>
