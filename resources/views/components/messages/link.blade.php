@props(['project'])

<a href="{{ route('projects.messages', $project) }}" {{ $attributes->class('tb-text-link inline-flex items-center gap-2 rounded-lg') }} wire:navigate>
    <span>Project messages</span><livewire:messages.unread-badge :project-id="$project->id" :key="'project-message-badge-'.$project->id" />
</a>
