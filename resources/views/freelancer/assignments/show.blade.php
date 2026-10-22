<x-layouts.app title="Assigned project">
    <div class="tb-form-editor mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="tb-eyebrow mb-2">Assigned project</p>
                <h1 class="break-words text-2xl font-bold tracking-tight sm:text-3xl">{{ $assignment->project->title }}</h1>
                <p class="tb-muted mt-2 break-words">Client: {{ $assignment->project->client->name }}</p>
                <p class="tb-muted mt-1">{{ $assignment->project->category->name }} · Status: {{ Str::headline($assignment->project->status->value) }}</p>
            </div>
            <flux:button :href="route('freelancer.assignments.index')" class="self-start shrink-0" wire:navigate>Assigned projects</flux:button>
        </div>
        <x-projects.cover :project="$assignment->project" />
        <x-projects.completion :record="$assignment->project" />
        <x-payments.summary :project="$assignment->project" :assignment="$assignment" />
        <x-disputes.notice :project="$assignment->project" :assignment="$assignment" />
        @can('viewConversation', [App\Models\ProjectMessage::class, $assignment, $assignment->project])
            <x-messages.link :project="$assignment->project" />
        @endcan
        <x-dashboard.panel title="Agreed terms">
            <x-assignments.terms :assignment="$assignment" class="p-5 sm:p-6" />
        </x-dashboard.panel>
        <div class="flex flex-wrap gap-3">
            <flux:button :href="route('projects.activity', $assignment->project_id)" wire:navigate>Project activity</flux:button>
            <flux:button :href="route('projects.completion', $assignment->project_id)" wire:navigate>Project completion</flux:button>
            <x-feedback.link :project="$assignment->project" :assignment="$assignment" />
            <flux:button :href="route('projects.plan', $assignment->project_id)" icon="calendar-days" wire:navigate>Project plan</flux:button>
            <flux:button :href="route('projects.deliverables.index', $assignment->project_id)" icon="document-text" wire:navigate>Deliverables</flux:button>
        </div>
        <x-dashboard.panel title="Project task progress" description="Task completion does not represent Client approval or project acceptance.">
            <x-tasks.progress :progress="$taskProgress" class="p-5 sm:p-6" />
        </x-dashboard.panel>
        <x-dashboard.panel title="Project brief">
            <p class="whitespace-pre-wrap break-words p-5 leading-7 sm:p-6">{{ $assignment->project->description }}</p>
        </x-dashboard.panel>
        <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
            <p>Your agreed terms are recorded separately from the original project brief and offer. Your proposal history remains available.</p>
            <flux:button :href="route('freelancer.proposals.show', $assignment->proposal_id)" class="mt-4" wire:navigate>View your accepted proposal</flux:button>
        </div>
    </div>
</x-layouts.app>
