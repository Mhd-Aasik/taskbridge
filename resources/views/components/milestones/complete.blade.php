@props(['milestone', 'readiness'])

<div class="mt-5 space-y-3" x-data>
    <p class="tb-muted">{{ $readiness['tasks'] }} task(s), {{ $readiness['deliverables'] }} deliverable(s). Completion uses all saved work, regardless of task filters.</p>
    @if ($readiness['requires_note'])<p class="tb-muted">This milestone has no work items. Explain its outcome in a completion note.</p>@endif
    @foreach ($readiness['reasons'] as $reason)<p class="tb-muted">{{ $reason }}</p>@endforeach
    <flux:error :name="'milestoneCompletion.'.$milestone->id" />
    <flux:button type="button" x-on:click="$refs.milestoneDialog.showModal()" :disabled="! $readiness['ready']" :aria-label="'Complete milestone '.$milestone->title">Complete milestone</flux:button>
    <dialog x-ref="milestoneDialog" aria-labelledby="complete-milestone-{{ $milestone->id }}" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
        <h3 id="complete-milestone-{{ $milestone->id }}" class="text-lg font-semibold">Complete {{ $milestone->title }}?</h3>
        <p class="tb-muted mt-3 leading-7">Completion locks this milestone’s details, task changes and membership, deliverables and further versions. It does not complete the whole project or acknowledge payment.</p>
        <form wire:submit="completeMilestone({{ $milestone->id }})" class="mt-5 space-y-5">
            <flux:error :name="'milestoneCompletion.'.$milestone->id" />
            <flux:textarea wire:model="milestoneNotes.{{ $milestone->id }}" :label="$readiness['requires_note'] ? 'Outcome note (required)' : 'Completion note (optional)'" description="Plain text, up to 2,000 characters." maxlength="2000" rows="4" />
            <flux:error :name="'milestoneNotes.'.$milestone->id" />
            <flux:checkbox wire:model="milestoneConfirmed.{{ $milestone->id }}" label="I confirm completion of this milestone." />
            <flux:error :name="'milestoneConfirmed.'.$milestone->id" />
            <div class="flex flex-wrap justify-end gap-3">
                <flux:button type="button" x-on:click="$refs.milestoneDialog.close()" wire:loading.attr="disabled" wire:target="completeMilestone" autofocus>Keep milestone open</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="completeMilestone">Confirm milestone completion</flux:button>
            </div>
            <p role="status" aria-live="polite" wire:loading wire:target="completeMilestone">Recording milestone completion...</p>
        </form>
    </dialog>
</div>
