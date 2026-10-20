@props(['assignment'])

<x-dashboard.panel title="Project assignment">
    <div class="space-y-5 p-5 sm:p-6">
        <p class="break-words font-semibold">Assigned freelancer: {{ $assignment->freelancer->name }}</p>
        <x-assignments.terms :assignment="$assignment" />
        <flux:button :href="route('client.projects.proposals.show', [$assignment->project_id, $assignment->proposal_id])" wire:navigate>View accepted proposal</flux:button>
    </div>
</x-dashboard.panel>
