<x-layouts.app title="Browse projects">
    <div class="tb-form-editor mx-auto max-w-5xl space-y-6">
        <x-dashboard.heading title="Browse projects" description="Explore open projects and find your next collaboration." />

        <x-dashboard.panel title="Find the right project">
            <form method="GET" action="{{ route('freelancer.projects.index') }}" class="space-y-5 p-5 sm:p-6" x-data="{ searching: false }" x-on:submit="searching = true" x-on:pageshow.window="searching = false" x-on:livewire:navigated.window="searching = false" x-bind:aria-busy="searching">
                <flux:input name="search" :value="$filters['search']" label="Search projects" placeholder="Title or description" maxlength="150" :invalid="$filterErrors->has('search')" :error:message="$filterErrors->first('search')" />
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <flux:select name="category" label="Category" :invalid="$filterErrors->has('category')" :error:message="$filterErrors->first('category')">
                        <option value="">All active categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select name="sort" label="Sort projects" :invalid="$filterErrors->has('sort')" :error:message="$filterErrors->first('sort')">
                        @foreach ($sorts as $value => $label)
                            <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input name="min_budget" :value="$filters['min_budget']" label="Minimum budget (LKR)" inputmode="decimal" placeholder="No minimum" maxlength="13" :invalid="$filterErrors->has('min_budget')" :error:message="$filterErrors->first('min_budget')" />
                    <flux:input name="max_budget" :value="$filters['max_budget']" label="Maximum budget (LKR)" inputmode="decimal" placeholder="No maximum" maxlength="13" :invalid="$filterErrors->has('max_budget')" :error:message="$filterErrors->first('max_budget')" />
                </div>
                <p class="tb-muted text-sm leading-6">Budgets are fixed amounts in LKR. Use digits and up to two decimal places.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary" icon="magnifying-glass" x-bind:disabled="searching">Find projects</flux:button>
                    <flux:button :href="route('freelancer.projects.index')" variant="ghost" wire:navigate>Clear filters</flux:button>
                    <span x-cloak x-show="searching" role="status" class="tb-muted text-sm">Finding projects...</span>
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
        @elseif ($projects->isEmpty())
            <x-dashboard.panel title="Project discovery">
                @if ($projects->total() > 0)
                    <x-dashboard.empty-state icon="magnifying-glass" title="No projects on this page." description="Use the page links below to return to the available results." />
                    <div class="px-5 pb-5 sm:px-6">{{ $projects->links() }}</div>
                @else
                    <x-dashboard.empty-state icon="magnifying-glass" :title="$hasFilters ? 'No projects match your filters.' : 'No open projects yet.'" :description="$hasFilters ? 'Try a broader search, a different category, or a wider budget range.' : 'New opportunities will appear here when clients publish projects. Check back soon.'" />
                @endif
            </x-dashboard.panel>
        @else
            <p class="tb-muted text-sm" role="status">{{ $projects->total() }} {{ Str::plural('project', $projects->total()) }} available{{ $hasFilters ? ' with these filters' : '' }}. Deadlines use Sri Lanka's calendar date.</p>
            <div class="space-y-4" aria-label="Available projects">
                @foreach ($projects as $project)
                    <x-projects.discovery-card :project="$project" />
                @endforeach
            </div>
            {{ $projects->links() }}
        @endif
    </div>
</x-layouts.app>
