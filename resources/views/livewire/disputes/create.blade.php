<?php

use App\Actions\Disputes\OpenDispute;
use App\Livewire\Concerns\HandlesDisputeActions;
use App\Models\Project;
use App\Support\DisputeContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Open a dispute')] class extends Component {
    use HandlesDisputeActions;

    #[Locked]
    public int $projectId;
    #[Locked]
    public string $submissionId;
    public $title = '';
    public $reason = '';
    public $confirmed = false;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->submissionId = (string) Str::uuid();
        DisputeContext::forProject($this->projectId);
    }

    public function openDispute(): void
    {
        $this->resetValidation();
        $dispute = $this->performDisputeAction(fn () => app(OpenDispute::class)->open($this->projectId, ['title' => $this->title, 'reason' => $this->reason, 'confirmed' => $this->confirmed], $this->submissionId), 'dispute');
        if (! $dispute) { return; }
        session()->flash('dispute-saved', $dispute->submission_id === $this->submissionId ? 'Dispute opened. The original case text is preserved.' : 'An active dispute already exists. Continue the private discussion in this case.');
        $this->redirectRoute('disputes.show', ['dispute' => $dispute->id], navigate: true);
    }

    public function with(): array
    {
        [, $project, $assignment] = DisputeContext::forProject($this->projectId);
        return ['project' => $project, 'assignment' => $assignment->load('activeDispute')];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-3xl space-y-6">
    <div><p class="tb-eyebrow mb-2">Private case review</p><h1 class="text-2xl font-bold sm:text-3xl">Open a dispute</h1><p class="tb-muted mt-2 break-words">{{ $project->title }}</p></div>
    <x-projects.cover :project="$project" />
    <x-assignments.terms :assignment="$assignment" />
    <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 leading-7 dark:border-teal-900">A dispute pauses new milestone and project completion. Normal authorised work and external payment tracking continue. Existing completion, agreed terms, files and payment history are preserved. Opening a case does not transfer, freeze or refund money.</div>
    <flux:error name="dispute" />
    @if ($assignment->activeDispute)
        <p class="font-medium">An active dispute already exists for this assignment.</p>
        <flux:button :href="route('disputes.show', $assignment->activeDispute->dispute_case_id)" wire:navigate>Continue existing case</flux:button>
    @else
        <form wire:submit="openDispute" class="space-y-5" x-data>
            <fieldset wire:loading.attr="disabled" wire:target="openDispute" class="space-y-5">
                <legend class="sr-only">Original dispute details</legend>
                <flux:input wire:model="title" label="Case title (required)" description="Up to 150 characters." maxlength="150" aria-required="true" />
                <flux:textarea wire:model="reason" label="Reason for the dispute (required)" description="Explain the concern in plain text, up to 5,000 characters. The original text cannot be edited after opening." maxlength="5000" rows="7" aria-required="true" />
            </fieldset>
            <flux:button type="button" variant="primary" x-on:click="$refs.openDialog.showModal()" wire:loading.attr="disabled" wire:target="openDispute">Review and open dispute</flux:button>
            <dialog x-ref="openDialog" aria-labelledby="open-dispute-heading" aria-describedby="open-dispute-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
                <h2 id="open-dispute-heading" class="text-lg font-semibold">Open this private dispute?</h2>
                <p id="open-dispute-explanation" class="tb-muted mt-3 leading-7">Your title and reason will become permanent case history shared with the other participant and authorised Admins. New milestone and project completion will be blocked until an Admin resolves or dismisses the case.</p>
                <flux:error name="dispute" /><flux:error name="title" /><flux:error name="reason" />
                <div class="mt-5 space-y-5">
                    <flux:checkbox wire:model="confirmed" label="I confirm I want to open this dispute with the details entered above." />
                    <flux:error name="confirmed" />
                    <div class="flex flex-wrap justify-end gap-3">
                        <flux:button type="button" x-on:click="$refs.openDialog.close()" wire:loading.attr="disabled" wire:target="openDispute" autofocus>Keep reviewing</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="openDispute">Confirm open dispute</flux:button>
                    </div>
                    <p role="status" aria-live="polite" wire:loading wire:target="openDispute">Opening private case...</p>
                </div>
            </dialog>
        </form>
    @endif
    <flux:button :href="route('disputes.index')" wire:navigate>Your dispute history</flux:button>
</div>
