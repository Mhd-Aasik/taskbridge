<x-layouts.app title="Proposal review">
    <div class="tb-form-editor mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="tb-eyebrow mb-2">Private proposal review</p>
                <h1 class="break-words text-2xl font-bold tracking-tight sm:text-3xl">Proposal from {{ $proposal->freelancer->name }}</h1>
                <div class="mt-4 flex items-start gap-4">
                    <x-projects.cover :project="$project" compact />
                    <div class="min-w-0">
                        <p class="break-words font-medium">{{ $project->title }}</p>
                        <p class="tb-muted mt-1">Status: {{ Str::headline($proposal->status->value) }}</p>
                    </div>
                </div>
            </div>
            <flux:button :href="route('client.projects.proposals.index', $project)" class="self-start shrink-0" icon="arrow-left" wire:navigate>All proposals</flux:button>
        </div>

        <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('proposal-acceptance') }}</p>
        @if ($errors->getBag('acceptance')->any())
            <section role="alert" aria-labelledby="acceptance-errors" class="rounded-xl border border-red-300 bg-red-50 p-5 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">
                <h2 id="acceptance-errors" class="font-semibold">Proposal not accepted</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5">
                    @foreach ($errors->getBag('acceptance')->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
        @if ($project->assignment)
            <x-assignments.client-summary :assignment="$project->assignment" />
        @endif

        <x-dashboard.panel title="Submitted offer">
            <x-feedback.reputation :summary="$freelancerReputation" class="px-5 pt-5 sm:px-6 sm:pt-6" />
            @if ($visibleProfileId)<div class="px-5 pt-4 sm:px-6"><flux:button :href="route('client.freelancers.show', $visibleProfileId)" wire:navigate>View profile</flux:button></div>@endif
            <x-proposals.review-offer :proposal="$proposal" :project="$project" class="p-5 sm:p-6" />
        </x-dashboard.panel>
        <x-dashboard.panel title="Cover letter">
            <p class="whitespace-pre-wrap break-words p-5 leading-7 sm:p-6">{{ $proposal->cover_letter }}</p>
        </x-dashboard.panel>
        <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
            <p>These proposal details are available to you as the project owner.</p>
        </div>

        @if (! $project->assignment && $project->status === \App\Enums\ProjectStatus::Open && $project->published_at && $project->published_at->lessThanOrEqualTo(now('UTC')) && $project->category->is_active && $proposal->status === \App\Enums\ProposalStatus::Submitted)
            @can('accept', [$proposal, $project])
                <div x-data="{ accepting: false }" x-on:pageshow.window="accepting = false" x-on:livewire:navigated.window="accepting = false">
                    <flux:button type="button" variant="primary" x-on:click="$refs.acceptanceDialog.showModal()">Accept proposal</flux:button>
                    <dialog x-ref="acceptanceDialog" aria-labelledby="accept-heading" aria-describedby="accept-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
                        <h2 id="accept-heading" class="text-lg font-semibold">Accept this proposal?</h2>
                        <p class="mt-3 break-words font-semibold">Freelancer: {{ $proposal->freelancer->name }}</p>
                        <x-proposals.review-offer :proposal="$proposal" :project="$project" class="mt-5" />
                        <p class="tb-muted mt-5">Advertised project deadline: @if ($deadline = $project->calendarDeadline())<time datetime="{{ $deadline->toDateString() }}">{{ $deadline->format('j F Y') }}</time>@else Invalid saved deadline @endif</p>
                        <p id="accept-explanation" class="tb-muted mt-4 leading-7">Accepting selects this freelancer at the offered budget and completion date, starts the project, and closes all other submitted offers. Previously rejected and withdrawn offers remain unchanged. You cannot select another freelancer after confirmation.</p>
                        <form method="POST" action="{{ route('client.projects.proposals.accept', [$project, $proposal]) }}" x-on:submit="accepting = true" x-bind:aria-busy="accepting" class="mt-6 space-y-4">
                            @csrf
                            <input type="hidden" name="confirmed" value="1" />
                            <div class="flex flex-wrap justify-end gap-3">
                                <flux:button type="button" x-on:click="$refs.acceptanceDialog.close()" x-bind:disabled="accepting" autofocus>Keep reviewing</flux:button>
                                <flux:button type="submit" variant="primary" x-bind:disabled="accepting"><span x-text="accepting ? 'Accepting...' : 'Confirm acceptance'">Confirm acceptance</span></flux:button>
                            </div>
                            <p role="status" aria-live="polite" x-show="accepting" x-cloak>Accepting proposal...</p>
                        </form>
                    </dialog>
                </div>
            @endcan
        @endif
    </div>
</x-layouts.app>
