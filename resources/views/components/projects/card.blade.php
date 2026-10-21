@props(['project'])

<article {{ $attributes->class('tb-panel p-5 sm:p-6') }}>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 items-start gap-4">
            <x-projects.cover :project="$project" compact />
            <div class="min-w-0 space-y-2">
                <p class="tb-muted text-sm">{{ $project->category->name }}{{ $project->category->is_active ? '' : ' (inactive)' }}</p>
                <h2 class="break-words text-lg font-semibold tracking-tight"><a href="{{ route('client.projects.show', $project) }}" class="rounded-sm hover:text-teal-700 dark:hover:text-teal-300" wire:navigate>{{ $project->title }}</a></h2>
                <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm font-medium text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">{{ Str::headline($project->status->value) }}</span>
            </div>
        </div>
        @can('update', $project)
            <flux:button :href="route('client.projects.edit', $project)" icon="pencil-square" class="self-start shrink-0" wire:navigate :aria-label="'Edit draft: '.$project->title">Edit draft</flux:button>
        @endcan
    </div>
    <p class="tb-muted mt-4 whitespace-pre-wrap break-words text-base leading-7">{{ Str::limit($project->description, 220) }}</p>
    <x-projects.inactive-category :project="$project" class="mt-4" />
    <dl class="mt-5 grid gap-4 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2 dark:border-slate-700">
        <div><dt class="tb-muted">Fixed budget</dt><dd class="mt-1 text-base font-medium tabular-nums">{{ $project->formattedBudget() ?? 'Budget needs correction.' }}</dd></div>
        <div><dt class="tb-muted">Deadline</dt><dd class="mt-1 text-base font-medium">@if ($deadline = $project->calendarDeadline())<time datetime="{{ $deadline->toDateString() }}">{{ $deadline->format('j M Y') }}</time>@else Deadline needs correction. @endif</dd></div>
    </dl>
    @can('reviewAny', [\App\Models\Proposal::class, $project])
        @if ($project->assignment)
            <p class="tb-muted mt-4 break-words text-sm">Assigned to {{ $project->assignment->freelancer->name }} · {{ $project->assignment->formattedBudget() }} · {{ $project->assignment->agreed_deadline->format('j M Y') }}</p>
        @endif
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-700">
            <p class="tb-muted text-sm">{{ $project->proposals_count }} {{ Str::plural('proposal', $project->proposals_count) }}</p>
            <flux:button :href="route('client.projects.proposals.index', $project)" :aria-label="'View proposals for '.$project->title" wire:navigate>View proposals</flux:button>
        </div>
    @endcan
</article>
