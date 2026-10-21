@props(['project', 'assignment'])

@if ($project->status === \App\Enums\ProjectStatus::Completed)
    @can('viewAny', [\App\Models\ProjectRating::class, $assignment, $project])
        <flux:button :href="route('projects.feedback', $project)" wire:navigate>Project feedback</flux:button>
    @endcan
@endif
