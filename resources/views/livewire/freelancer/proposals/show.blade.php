<?php

use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Your proposal')] class extends Component {
    #[Locked]
    public int $proposalId;

    public function mount(Proposal $proposal): void
    {
        $this->proposalId = $proposal->id;
    }

    public function with(): array
    {
        $user = User::query()->find(Auth::id());
        abort_unless($user, 403);
        $proposal = Proposal::query()->findOrFail($this->proposalId);
        Gate::forUser($user)->authorize('view', $proposal);

        return [
            'proposal' => $proposal->load('project:id,title,budget'),
            'projectDiscoverable' => Project::query()->discoverable()->whereKey($proposal->project_id)->exists(),
            'submissionTime' => $proposal->submitted_at->setTimezone('Asia/Colombo'),
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="tb-eyebrow mb-2">Your proposal</p>
            <h1 class="break-words text-2xl font-bold tracking-tight sm:text-3xl">{{ $proposal->project->title }}</h1>
            <p class="tb-muted mt-2">Status: {{ Str::headline($proposal->status->value) }}</p>
        </div>
        <flux:button :href="route('freelancer.proposals.index')" class="self-start shrink-0" wire:navigate>My proposals</flux:button>
    </div>

    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('proposal-submission') }}</p>
    <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        <p>Submitting a proposal does not assign the project or guarantee acceptance. This is a read-only record of your offer.</p>
        @if ($projectDiscoverable)
            <flux:button :href="route('freelancer.projects.show', $proposal->project_id)" class="mt-4" wire:navigate>View project</flux:button>
        @else
            <p class="mt-3">This project is no longer available in discovery. Your proposal history remains available here.</p>
        @endif
    </div>
    <x-dashboard.panel title="Your submitted offer">
        <dl class="grid gap-6 p-5 sm:grid-cols-2 sm:p-6">
            <div><dt class="tb-muted text-sm">Your proposed fixed budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $proposal->formattedBudget() }}</dd></div>
            <div><dt class="tb-muted text-sm">Advertised project budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $proposal->project->formattedBudget() }}</dd></div>
            <div><dt class="tb-muted text-sm">Your proposed completion date</dt><dd class="mt-1"><time datetime="{{ $proposal->proposed_deadline->toDateString() }}">{{ $proposal->proposed_deadline->format('j F Y') }}</time></dd></div>
            <div><dt class="tb-muted text-sm">Submitted in Sri Lanka</dt><dd class="mt-1"><time datetime="{{ $submissionTime->toIso8601String() }}">{{ $submissionTime->format('j F Y, g:i a') }} (Asia/Colombo)</time></dd></div>
        </dl>
    </x-dashboard.panel>
    <x-dashboard.panel title="Your cover letter">
        <p class="whitespace-pre-wrap break-words p-5 leading-7 sm:p-6">{{ $proposal->cover_letter }}</p>
    </x-dashboard.panel>
</div>
