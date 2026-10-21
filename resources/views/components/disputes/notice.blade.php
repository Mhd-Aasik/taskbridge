@props(['project', 'assignment'])

<section aria-label="Project disputes" {{ $attributes->class('tb-editor-notice space-y-3 rounded-xl border border-teal-200 p-5 dark:border-teal-900') }}>
    @if ($assignment->activeDispute)
        <p class="font-semibold">An active dispute is open for this assignment.</p>
        <p class="leading-7">New milestone and project completion are blocked until the case is resolved or dismissed. Existing work, payment reporting and receipt acknowledgment continue under their usual rules. Recorded completion is preserved.</p>
        <flux:button :href="route('disputes.show', $assignment->activeDispute->dispute_case_id)" wire:navigate>View active dispute</flux:button>
    @else
        <p class="tb-muted">No active dispute for this assignment. Both participants can open a private case, including after project completion.</p>
        <flux:button :href="route('projects.disputes.create', $project)" wire:navigate>Open a dispute</flux:button>
    @endif
    <a class="tb-text-link inline-block" href="{{ route('disputes.index') }}" wire:navigate>Your dispute history</a>
</section>
