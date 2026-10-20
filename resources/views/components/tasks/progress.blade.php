@props(['progress', 'label' => 'Task progress'])

<div {{ $attributes->class('space-y-2') }}>
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <p class="font-medium">{{ $label }}</p>
        <p class="tb-muted text-sm tabular-nums">{{ $progress['completed'] }} of {{ $progress['total'] }} tasks done</p>
    </div>
    @if ($progress['total'] > 0)
        <progress value="{{ $progress['completed'] }}" max="{{ $progress['total'] }}" aria-label="{{ $label }}" class="block h-2 w-full overflow-hidden rounded-full accent-teal-700 dark:accent-teal-400">{{ $progress['percentage'] }}%</progress>
        <p class="tb-muted text-sm">{{ $progress['percentage'] }}% task completion</p>
    @else
        <p class="tb-muted text-sm">No tasks yet.</p>
    @endif
</div>
