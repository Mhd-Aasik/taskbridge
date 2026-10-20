@props(['task', 'canManage', 'canChangeStatus', 'statuses'])

<li {{ $attributes->class('rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-950/30') }} wire:key="project-task-{{ $task->id }}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h3 class="break-words font-semibold">{{ $task->title }}</h3>
            <p class="tb-muted mt-2 text-sm">Due <time datetime="{{ $task->due_date->toDateString() }}">{{ $task->due_date->format('j F Y') }}</time></p>
            <p class="mt-2 text-sm font-medium">Status: {{ $task->status->label() }}</p>
            @if ($task->isOverdue())
                <p class="mt-2 text-sm font-semibold text-amber-900 dark:text-amber-200">Overdue <span class="font-normal">— due before today in Sri Lanka.</span></p>
            @endif
            @if ($task->completed_at !== null)
                <p class="tb-muted mt-2 text-sm">Completed <time datetime="{{ $task->completed_at->setTimezone('Asia/Colombo')->toIso8601String() }}">{{ $task->completed_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</p>
            @endif
        </div>
        @if ($canManage)
            <flux:button type="button" wire:click="editTask({{ $task->id }})" wire:loading.attr="disabled" wire:target="editTask,saveTask,cancelTaskEdit,changeTaskStatus" :aria-label="'Edit task '.$task->title" class="self-start shrink-0">Edit task</flux:button>
        @endif
    </div>
    @if ($task->description !== null && $task->description !== '')
        <p class="mt-4 whitespace-pre-wrap break-words leading-7">{{ $task->description }}</p>
    @endif
    @if ($canChangeStatus)
        <form wire:submit="changeTaskStatus({{ $task->id }}, $event.target.elements.status.value, {{ $task->status_version }})" class="mt-4 flex flex-col items-start gap-3 sm:flex-row sm:items-end" aria-label="Change status for {{ $task->title }}">
            <fieldset wire:loading.attr="disabled" wire:target="changeTaskStatus,editTask,saveTask,cancelTaskEdit" class="min-w-0 w-full sm:max-w-xs">
                <legend class="sr-only">Task status</legend>
                <flux:select id="task-status-{{ $task->id }}" name="status" :label="'Task status for '.$task->title">
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($task->status === $status)>{{ $status->label() }}</option>
                    @endforeach
                </flux:select>
            </fieldset>
            <flux:button type="submit" wire:loading.attr="disabled" wire:target="changeTaskStatus,editTask,saveTask,cancelTaskEdit" :aria-label="'Update status for '.$task->title">Update task status</flux:button>
        </form>
    @endif
</li>
