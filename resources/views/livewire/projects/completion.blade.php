<?php

use App\Actions\Projects\CompleteProject;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\CompletionReadiness;
use App\Support\ProjectPlanning;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Project completion')] class extends Component {
    #[Locked]
    public int $projectId;

    public $completion_note = '';

    public $confirmed = false;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        ProjectPlanning::context($this->projectId);
    }

    public function complete(): void
    {
        $this->resetValidation();
        app(CompleteProject::class)->complete($this->projectId, ['completion_note' => $this->completion_note, 'confirmed' => $this->confirmed]);
        session()->flash('project-completed', 'Project completed. Agreed terms and all work history are preserved. Payment status has not changed.');
    }

    public function with(): array
    {
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);

        return [
            'project' => $project->load('completer:id,name'),
            'assignment' => $assignment->load('activeDispute'),
            'readiness' => CompletionReadiness::forProject($assignment),
            'canComplete' => $user->isClient() && $project->client_id === $user->id && $project->status === ProjectStatus::InProgress && ! $project->isCompleted(),
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="tb-eyebrow mb-2">Private project workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Project completion</h1>
            <p class="mt-2 break-words font-medium">{{ $project->title }}</p>
            <p class="tb-muted mt-1">Status: {{ Str::headline($project->status->value) }}</p>
        </div>
        <flux:button :href="route('projects.plan', $project)" wire:navigate>Project plan</flux:button>
    </div>
    <x-projects.cover :project="$project" />
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('project-completed') }}</p>
    <flux:error name="completion" />
    <x-projects.completion :record="$project" />
    <x-disputes.notice :project="$project" :assignment="$assignment" />
    <x-dashboard.panel title="Completion requirements" description="Based on all persisted work, including ungrouped tasks and deliverables. Task filters do not affect these counts.">
        <div class="space-y-5 p-5 sm:p-6">
            <dl class="grid gap-5 sm:grid-cols-3">
                <div><dt class="tb-muted">Explicitly completed milestones</dt><dd class="mt-1 font-semibold">{{ $readiness['completed_milestones'] }} / {{ $readiness['milestones'] }}</dd></div>
                <div><dt class="tb-muted">Tasks done</dt><dd class="mt-1 font-semibold">{{ $readiness['completed_tasks'] }} / {{ $readiness['tasks'] }}</dd></div>
                <div><dt class="tb-muted">Latest deliverable versions approved</dt><dd class="mt-1 font-semibold">{{ $readiness['approved_deliverables'] }} / {{ $readiness['deliverables'] }}</dd></div>
            </dl>
            @if ($readiness['ready'])
                <p class="font-medium">All work requirements are satisfied. Only the owning Client can explicitly confirm project completion while it is in progress.</p>
            @else
                <p class="font-medium">Outstanding requirements</p>
                <ul class="list-disc space-y-2 pl-5">
                    @foreach ($readiness['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                </ul>
            @endif
            <div class="flex flex-wrap gap-3">
                <flux:button :href="route('projects.plan', $project)" wire:navigate>Review milestones and tasks</flux:button>
                <flux:button :href="route('projects.deliverables.index', $project)" wire:navigate>Review deliverables</flux:button>
            </div>
        </div>
    </x-dashboard.panel>
    <p class="tb-muted leading-7">Task progress, deliverable approval and project completion are separate records. Completion preserves agreed terms and all history. It does not mark anything paid or acknowledge payment receipt. Reopening is unavailable.</p>
    @if ($canComplete)
        <div x-data>
            <flux:button type="button" variant="primary" x-on:click="$refs.completionDialog.showModal()" :disabled="! $readiness['ready']">Complete project</flux:button>
            <dialog x-ref="completionDialog" aria-labelledby="complete-project-heading" aria-describedby="complete-project-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
                <h2 id="complete-project-heading" class="text-lg font-semibold">Complete {{ $project->title }}?</h2>
                <p id="complete-project-explanation" class="tb-muted mt-3 leading-7">This permanently records project completion and locks further work changes. Files, reviews and agreed terms remain available. This does not mark the project paid or acknowledge payment receipt.</p>
                <form wire:submit="complete" class="mt-5 space-y-5">
                    <flux:error name="completion" />
                    <flux:textarea wire:model="completion_note" label="Completion note (optional)" description="Plain text, up to 2,000 characters." maxlength="2000" rows="4" />
                    <flux:checkbox wire:model="confirmed" label="I confirm completion of this project." />
                    <flux:error name="confirmed" />
                    <div class="flex flex-wrap justify-end gap-3">
                        <flux:button type="button" x-on:click="$refs.completionDialog.close()" wire:loading.attr="disabled" wire:target="complete" autofocus>Keep in progress</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="complete">Confirm project completion</flux:button>
                    </div>
                    <p role="status" aria-live="polite" wire:loading wire:target="complete">Recording completion...</p>
                </form>
            </dialog>
        </div>
    @elseif (! $project->isCompleted())
        <p class="tb-muted">Only the owning Client may complete an assigned project while it is in progress. Historical records remain read-only.</p>
    @endif
</div>
