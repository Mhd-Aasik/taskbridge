@props(['project'])

@if (! $project->category->is_active)
    <p role="note" {{ $attributes->class('tb-editor-notice rounded-xl border border-teal-200 p-5 text-sm leading-7 dark:border-teal-900') }}>This project's category is inactive. New projects and publication cannot use it, and associated open projects are unavailable in Freelancer discovery. Existing assigned work and project history remain available.@if ($project->status === \App\Enums\ProjectStatus::Draft) Select an active category in the draft editor before saving or publishing.@endif</p>
@endif
