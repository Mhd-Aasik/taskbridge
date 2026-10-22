<x-layouts.app title="Find freelancers">
    <div class="tb-form-editor mx-auto max-w-5xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <x-dashboard.heading title="Find freelancers" description="Discover professionals who have chosen to share their profiles with Clients." />
            <flux:button :href="route('client.projects.create')" variant="primary" class="self-start shrink-0" wire:navigate>Post a project</flux:button>
        </div>
        <p class="tb-muted leading-7">Start a collaboration by posting a project and reviewing submitted proposals. A profile's hourly rate is an indication; project offers set their own fixed terms.</p>
        <x-dashboard.panel title="Find the right skills">
            <form method="GET" action="{{ route('client.freelancers.index') }}" class="space-y-5 p-5 sm:p-6" x-data="{ searching: false }" x-on:submit="searching = true" x-on:pageshow.window="searching = false" x-on:livewire:navigated.window="searching = false" x-bind:aria-busy="searching">
                <flux:input name="search" :value="$filters['search']" label="Search freelancers" placeholder="Name, headline, or biography" maxlength="150" :invalid="$filterErrors->has('search')" :error:message="$filterErrors->first('search')" />
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <flux:input name="location" :value="$filters['location']" label="Location" placeholder="Any location" maxlength="120" :invalid="$filterErrors->has('location')" :error:message="$filterErrors->first('location')" />
                    <flux:select name="sort" label="Sort profiles" :invalid="$filterErrors->has('sort')" :error:message="$filterErrors->first('sort')">
                        @foreach ($sorts as $key => $label)<option value="{{ $key }}" @selected($filters['sort'] === $key)>{{ $label }}</option>@endforeach
                    </flux:select>
                    <flux:input name="min_rate" :value="$filters['min_rate']" label="Minimum hourly rate (LKR)" inputmode="decimal" placeholder="No minimum" maxlength="13" :invalid="$filterErrors->has('min_rate')" :error:message="$filterErrors->first('min_rate')" />
                    <flux:input name="max_rate" :value="$filters['max_rate']" label="Maximum hourly rate (LKR)" inputmode="decimal" placeholder="No maximum" maxlength="13" :invalid="$filterErrors->has('max_rate')" :error:message="$filterErrors->first('max_rate')" />
                </div>
                <fieldset class="space-y-3">
                    <legend class="font-semibold">Skills</legend>
                    <p class="tb-muted text-sm leading-6">Profiles must match every selected skill. Choose up to 10.</p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($skills as $skill)
                            <label class="flex items-center gap-3"><input type="checkbox" name="skills[]" value="{{ $skill->id }}" @checked(in_array((string) $skill->id, array_map('strval', $filters['skills']), true)) class="size-4 rounded border-slate-400 accent-teal-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600" /><span>{{ $skill->name }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <p class="tb-muted text-sm leading-6">Unspecified rates are included unless a minimum or maximum is entered. Unrated profiles show “Not rated yet”.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary" x-bind:disabled="searching">Find freelancers</flux:button>
                    <flux:button :href="route('client.freelancers.index')" variant="ghost" wire:navigate>Clear filters</flux:button>
                    <span role="status" x-show="searching" x-cloak class="tb-muted">Finding freelancers…</span>
                </div>
            </form>
        </x-dashboard.panel>
        @if ($filterErrors->any())
            <section role="alert" class="rounded-xl border border-red-300 bg-red-50 p-5 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">
                <h2 class="font-semibold">Check your filters and try again.</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5">@foreach ($filterErrors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </section>
        @elseif ($profiles->isEmpty())
            <x-dashboard.panel title="Freelancer discovery"><x-dashboard.empty-state icon="users" title="No profiles match this page." description="Try fewer skills, a broader search, or clear your filters. Profiles appear when Freelancers choose to make them visible to Clients." /></x-dashboard.panel>
            {{ $profiles->links() }}
        @else
            <p class="tb-muted" role="status">{{ $profiles->total() }} {{ Str::plural('profile', $profiles->total()) }} found.</p>
            <div class="space-y-5" aria-label="Freelancer profiles">@foreach ($profiles as $profile)<x-freelancers.profile :profile="$profile" compact />@endforeach</div>
            {{ $profiles->links() }}
        @endif
    </div>
</x-layouts.app>
