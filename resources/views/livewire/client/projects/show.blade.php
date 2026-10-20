<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Project review')] class extends Component {
    #[Locked]
    public int $projectId;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    public function with(): array
    {
        $user = User::query()->find(Auth::id());
        abort_unless($user, 403);
        $project = Project::query()->findOrFail($this->projectId);
        Gate::forUser($user)->authorize('view', $project);

        return [
            'project' => $project->load(['category', 'assignment.freelancer:id,name'])->loadCount('proposals'),
            'deadline' => $project->calendarDeadline(),
            'publicationTime' => $project->published_at?->toImmutable()->setTimezone('Asia/Colombo'),
            'statusDescription' => match ($project->status) {
                ProjectStatus::Draft => 'This draft is private and is not available to freelancers. Review the saved details before publishing.',
                ProjectStatus::Open => 'This project is open for freelancer applications. Draft editing is no longer available.',
                ProjectStatus::InProgress => 'This project is in progress. Its details are read-only.',
                ProjectStatus::Completed => 'This project is completed. Its details are read-only.',
                ProjectStatus::Cancelled => 'This project is cancelled. Its details are read-only.',
            },
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="tb-eyebrow mb-2">Project review</p>
            <h1 class="break-words text-2xl font-bold tracking-tight sm:text-3xl">{{ $project->title }}</h1>
            <p class="tb-muted mt-2">{{ $project->category->name }}{{ $project->category->is_active ? '' : ' (inactive)' }}</p>
        </div>
        <flux:button :href="route('client.projects.index')" icon="arrow-left" class="self-start shrink-0" wire:navigate>All projects</flux:button>
    </div>

    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('project-publication') }}</p>

    @if ($errors->getBag('publication')->any())
        <section role="alert" aria-labelledby="publication-errors" class="rounded-xl border border-red-300 bg-red-50 p-5 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">
            <h2 id="publication-errors" class="font-semibold">Project not published</h2>
            <p class="mt-2">Correct the saved draft if needed, then confirm publication again.</p>
            <ul class="mt-3 list-disc space-y-2 pl-5">
                @foreach ($errors->getBag('publication')->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        <span class="text-sm font-semibold">Status: {{ Str::headline($project->status->value) }}</span>
        <p class="mt-2">{{ $statusDescription }}</p>
    </div>

    <x-dashboard.panel title="Saved project details">
        <dl class="grid gap-6 p-5 sm:grid-cols-2 sm:p-6">
            <div><dt class="tb-muted text-sm">Fixed budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $project->formattedBudget() ?? 'Budget needs correction.' }}</dd></div>
            <div><dt class="tb-muted text-sm">Calendar deadline</dt><dd class="mt-1 font-medium">@if ($deadline)<time datetime="{{ $deadline->toDateString() }}">{{ $deadline->format('j F Y') }}</time>@else Deadline needs correction. @endif</dd></div>
            <div class="sm:col-span-2"><dt class="tb-muted text-sm">Publication time</dt><dd class="mt-1">@if ($publicationTime)<time datetime="{{ $publicationTime->toIso8601String() }}">{{ $publicationTime->format('j F Y, g:i a') }} (Asia/Colombo)</time>@else Not published @endif</dd></div>
        </dl>
    </x-dashboard.panel>

    @if ($project->assignment)
        <x-assignments.client-summary :assignment="$project->assignment" />
    @endif

    <x-dashboard.panel title="Project description">
        <p class="whitespace-pre-wrap break-words p-5 leading-7 sm:p-6">{{ $project->description }}</p>
    </x-dashboard.panel>

    <div class="flex flex-wrap items-center gap-3">
        @if ($project->assignment)
            <flux:button :href="route('projects.plan', $project)" icon="calendar-days" wire:navigate>Project plan</flux:button>
        @endif
        <flux:button :href="route('client.projects.proposals.index', $project)" icon="document-text" wire:navigate>View proposals ({{ $project->proposals_count }})</flux:button>
        @can('update', $project)
            <flux:button :href="route('client.projects.edit', $project)" icon="pencil-square" wire:navigate>Edit draft</flux:button>
        @endcan
        @can('publish', $project)
            <div x-data="{ publishing: false }">
                <flux:button type="button" variant="primary" x-on:click="$refs.publicationDialog.showModal()">Publish project</flux:button>
                <dialog x-ref="publicationDialog" aria-labelledby="publish-heading" aria-describedby="publish-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
                    <h2 id="publish-heading" class="text-lg font-semibold">Publish this project?</h2>
                    <p id="publish-explanation" class="tb-muted mt-3 leading-7">Publishing <strong>{{ $project->title }}</strong> opens this saved project for freelancer applications. Draft editing will no longer be available.</p>
                    <form method="POST" action="{{ route('client.projects.publish', $project) }}" x-on:submit="publishing = true" x-bind:aria-busy="publishing" class="mt-6 space-y-4">
                        @csrf
                        <input type="hidden" name="confirmed" value="1" />
                        <div class="flex flex-wrap justify-end gap-3">
                            <flux:button type="button" x-on:click="$refs.publicationDialog.close()" x-bind:disabled="publishing" autofocus>Keep draft</flux:button>
                            <flux:button type="submit" variant="primary" x-bind:disabled="publishing"><span x-text="publishing ? 'Publishing...' : 'Confirm publication'">Confirm publication</span></flux:button>
                        </div>
                        <p role="status" aria-live="polite" x-show="publishing" x-cloak>Publishing project...</p>
                    </form>
                </dialog>
            </div>
        @endcan
    </div>
</div>
