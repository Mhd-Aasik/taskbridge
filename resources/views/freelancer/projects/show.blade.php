<x-layouts.app title="Project details">
    <div class="tb-form-editor mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="tb-eyebrow mb-2">Open project</p>
                <h1 class="break-words text-2xl font-bold tracking-tight sm:text-3xl">{{ $project->title }}</h1>
                <p class="tb-muted mt-2">{{ $project->category->name }}</p>
            </div>
            <flux:button :href="route('freelancer.projects.index')" icon="arrow-left" class="self-start shrink-0" wire:navigate>Browse projects</flux:button>
        </div>

        <x-dashboard.panel title="Project details">
            <dl class="grid gap-6 p-5 sm:grid-cols-2 sm:p-6">
                <div><dt class="tb-muted text-sm">Fixed budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $project->formattedBudget() }}</dd></div>
                <div><dt class="tb-muted text-sm">Calendar deadline</dt><dd class="mt-1 font-medium"><time datetime="{{ $deadline->toDateString() }}">{{ $deadline->format('j F Y') }}</time></dd></div>
                <div><dt class="tb-muted text-sm">Client</dt><dd class="mt-1 break-words">{{ $project->client->name }}</dd></div>
                <div><dt class="tb-muted text-sm">Published in Sri Lanka</dt><dd class="mt-1"><time datetime="{{ $publicationTime->toIso8601String() }}">{{ $publicationTime->format('j F Y, g:i a') }} (Asia/Colombo)</time></dd></div>
            </dl>
        </x-dashboard.panel>

        <x-dashboard.panel title="Project description">
            <p class="whitespace-pre-wrap break-words p-5 leading-7 sm:p-6">{{ $project->description }}</p>
        </x-dashboard.panel>

        <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
            <p>Submitting a proposal does not assign the project or guarantee acceptance.</p>
            @if ($ownProposal)
                <flux:button :href="route('freelancer.proposals.show', $ownProposal)" class="mt-4" wire:navigate>View your proposal</flux:button>
            @elseif ($submissionUnavailable)
                <p class="mt-3" role="note">{{ $submissionUnavailable }}</p>
            @else
                <flux:button :href="route('freelancer.proposals.create', $project)" variant="primary" class="mt-4" wire:navigate>Submit a proposal</flux:button>
            @endif
        </div>
    </div>
</x-layouts.app>
