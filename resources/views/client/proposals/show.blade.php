<x-layouts.app title="Proposal review">
    <div class="tb-form-editor mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="tb-eyebrow mb-2">Private proposal review</p>
                <h1 class="break-words text-2xl font-bold tracking-tight sm:text-3xl">Proposal from {{ $proposal->freelancer->name }}</h1>
                <p class="mt-2 break-words font-medium">{{ $project->title }}</p>
                <p class="tb-muted mt-1">Status: {{ Str::headline($proposal->status->value) }}</p>
            </div>
            <flux:button :href="route('client.projects.proposals.index', $project)" class="self-start shrink-0" icon="arrow-left" wire:navigate>All proposals</flux:button>
        </div>

        <x-dashboard.panel title="Submitted offer">
            <x-proposals.review-offer :proposal="$proposal" :project="$project" class="p-5 sm:p-6" />
        </x-dashboard.panel>
        <x-dashboard.panel title="Cover letter">
            <p class="whitespace-pre-wrap break-words p-5 leading-7 sm:p-6">{{ $proposal->cover_letter }}</p>
        </x-dashboard.panel>
        <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
            <p>These proposal details are available to you as the project owner.</p>
        </div>
    </div>
</x-layouts.app>
