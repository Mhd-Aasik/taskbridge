@props(['project'])

<article {{ $attributes->class('tb-panel p-5 sm:p-6') }}>
    <p class="tb-muted text-sm">{{ $project->category->name }}</p>
    <h2 class="mt-2 break-words text-lg font-semibold tracking-tight"><a href="{{ route('freelancer.projects.show', $project) }}" class="rounded-sm hover:text-teal-700 dark:hover:text-teal-300" wire:navigate>{{ $project->title }}</a></h2>
    <p class="tb-muted mt-3 whitespace-pre-wrap break-words text-base leading-7">{{ Str::limit($project->description, 220) }}</p>
    <dl class="mt-5 grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2 dark:border-slate-700">
        <div><dt class="tb-muted text-sm">Fixed budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $project->formattedBudget() }}</dd></div>
        <div><dt class="tb-muted text-sm">Deadline</dt><dd class="mt-1 font-medium"><time datetime="{{ $project->calendarDeadline()->toDateString() }}">{{ $project->calendarDeadline()->format('j M Y') }}</time></dd></div>
    </dl>
</article>
