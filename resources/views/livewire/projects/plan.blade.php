<?php

use App\Actions\Milestones\SaveMilestone;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskStatus;
use App\Livewire\Forms\MilestoneForm;
use App\Livewire\Forms\ProjectTaskForm;
use App\Models\Project;
use App\Support\MilestoneRules;
use App\Support\ProjectPlanning;
use App\Support\TaskProgress;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Project plan')] class extends Component
{
    public MilestoneForm $form;

    public ProjectTaskForm $taskForm;

    #[Locked]
    public int $projectId;

    #[Locked]
    public ?int $editingMilestoneId = null;

    #[Locked]
    public ?int $editingTaskId = null;

    #[Locked]
    public ?int $taskDetailsVersion = null;

    public $taskStatusFilter = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        ProjectPlanning::context($this->projectId);
    }

    public function edit(int $milestoneId): void
    {
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);
        $milestone = $assignment->milestones()->findOrFail($milestoneId);
        Gate::forUser($user)->authorize('update', [$milestone, $assignment, $project]);
        $this->resetValidation();
        $this->editingMilestoneId = $milestone->id;
        $this->form->loadMilestone($milestone);
        $this->dispatch('milestone-editing');
    }

    public function cancelEdit(): void
    {
        ProjectPlanning::context($this->projectId);
        $this->editingMilestoneId = null;
        $this->form->reset();
        $this->resetValidation();
    }

    public function save(): void
    {
        try {
            app(SaveMilestone::class)->save($this->projectId, $this->editingMilestoneId, $this->form->details());
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[in_array($key, MilestoneRules::FIELDS, true) ? 'form.'.$key : $key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        session()->flash('milestone-saved', $this->editingMilestoneId ? 'Milestone changes saved.' : 'Milestone added to the project plan.');
        $this->editingMilestoneId = null;
        $this->form->reset();
        $this->resetValidation();
    }

    public function editTask(int $taskId): void
    {
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);
        $task = $assignment->tasks()->findOrFail($taskId);
        Gate::forUser($user)->authorize('update', [$task, $assignment, $project]);
        $this->resetValidation();
        $this->editingTaskId = $task->id;
        $this->taskDetailsVersion = $task->details_version;
        $this->taskForm->loadTask($task);
        $this->dispatch('task-editing');
    }

    public function cancelTaskEdit(): void
    {
        ProjectPlanning::context($this->projectId);
        $this->editingTaskId = null;
        $this->taskDetailsVersion = null;
        $this->taskForm->reset();
        $this->resetValidation();
    }

    public function saveTask(): void
    {
        try {
            app(SaveProjectTask::class)->save($this->projectId, $this->editingTaskId, $this->taskForm->details(), $this->taskDetailsVersion);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[in_array($key, ProjectTaskForm::FIELDS, true) ? 'taskForm.'.$key : $key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        session()->flash('task-saved', $this->editingTaskId ? 'Task changes saved.' : 'Task added to the project plan.');
        $this->editingTaskId = null;
        $this->taskDetailsVersion = null;
        $this->taskForm->reset();
        $this->resetValidation();
    }

    public function changeTaskStatus(int $taskId, string $status, int $expectedStatusVersion): void
    {
        app(ChangeProjectTaskStatus::class)->change($this->projectId, $taskId, $status, $expectedStatusVersion);
        $this->resetValidation();
        session()->flash('task-saved', 'Task status saved. Task progress does not represent Client approval or project acceptance.');
    }

    public function updatedTaskStatusFilter(): void
    {
        $this->resetValidation('taskStatusFilter');
    }

    public function with(): array
    {
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);

        $statuses = ProjectTaskStatus::cases();
        $filterIsValid = is_string($this->taskStatusFilter) && in_array($this->taskStatusFilter, ['', ...array_column($statuses, 'value')], true);
        if (! $filterIsValid) {
            $this->addError('taskStatusFilter', 'Choose a supported task status or show all tasks.');
        }
        $tasks = $filterIsValid
            ? $assignment->tasks()->when($this->taskStatusFilter !== '', fn ($query) => $query->where('status', $this->taskStatusFilter))->orderBy('due_date')->orderBy('id')->get()
            : collect();
        $ungroupedCounts = $assignment->tasks()->whereNull('milestone_id')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed', [ProjectTaskStatus::Done->value])->first();

        return [
            'project' => $project,
            'assignment' => $assignment,
            'milestones' => $assignment->milestones()->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($query) => $query->where('status', ProjectTaskStatus::Done->value)])->get(),
            'tasksByMilestone' => $tasks->groupBy('milestone_id'),
            'ungroupedTasks' => $tasks->whereNull('milestone_id'),
            'taskProgress' => TaskProgress::forAssignment($assignment),
            'ungroupedProgress' => TaskProgress::summarize((int) $ungroupedCounts->total, (int) $ungroupedCounts->completed),
            'taskStatuses' => $statuses,
            'canChangeTaskStatuses' => $project->status === ProjectStatus::InProgress,
            'canManage' => Gate::forUser($user)->allows('managePlan', [$assignment, $project]),
            'isClientOwner' => $user->isClient() && $project->client_id === $user->id,
            'earliestDate' => $assignment->assigned_at->setTimezone('Asia/Colombo')->toDateString(),
            'latestDate' => $assignment->agreed_deadline->toDateString(),
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="tb-eyebrow mb-2">Private project workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Project plan</h1>
            <p class="mt-2 break-words font-medium">{{ $project->title }}</p>
            <p class="tb-muted mt-1">Status: {{ Str::headline($project->status->value) }}</p>
        </div>
        <flux:button :href="$isClientOwner ? route('client.projects.show', $project) : route('freelancer.assignments.show', $assignment)" icon="arrow-left" class="self-start shrink-0" wire:navigate>{{ $isClientOwner ? 'Project review' : 'Assigned project' }}</flux:button>
    </div>

    <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        <p>Plan milestone and task dates from <time datetime="{{ $earliestDate }}">{{ $assignment->assigned_at->setTimezone('Asia/Colombo')->format('j F Y') }}</time> through <time datetime="{{ $latestDate }}">{{ $assignment->agreed_deadline->format('j F Y') }}</time>, inclusive.</p>
        <p class="mt-2">Past dates within this interval are allowed so overdue work remains manageable. Planning does not change the agreed completion date or project status.</p>
        @if (! $canManage)
            <p class="mt-3 font-medium">{{ $isClientOwner ? 'This plan is read-only. Milestones can be changed only while the project is in progress.' : 'You can read this shared plan. Only the owning Client can create or edit milestones while the project is in progress.' }}</p>
        @endif
        <p class="mt-3">{{ $canChangeTaskStatuses ? 'Both participants can update task status. Only the owning Client can create or edit task details.' : 'Task history is read-only because this project is no longer in progress.' }} Task completion does not approve deliverables or complete milestones or the project.</p>
    </div>

    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('milestone-saved') }}</p>
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('task-saved') }}</p>
    <p role="status" aria-live="polite" wire:loading wire:target="changeTaskStatus">Saving task status...</p>
    <flux:error name="plan" />
    <flux:error name="task_conflict" />
    <flux:error name="task_status" />

    <x-dashboard.panel title="Project task progress" description="Calculated from all saved tasks. These counts describe task progress, not Client approval or project acceptance.">
        <div class="space-y-5 p-5 sm:p-6">
            <x-tasks.progress :progress="$taskProgress" label="Overall task progress" />
            <flux:select wire:model.live="taskStatusFilter" label="Filter visible tasks by status" description="Filtering does not change overall or milestone task progress.">
                <option value="">All task statuses</option>
                @foreach ($taskStatuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </flux:select>
            @if ($taskStatusFilter !== '')
                <flux:button type="button" variant="ghost" wire:click="$set('taskStatusFilter', '')" wire:loading.attr="disabled" wire:target="taskStatusFilter">Show all tasks</flux:button>
            @endif
        </div>
    </x-dashboard.panel>

    <x-dashboard.panel title="Milestones" description="Ordered by their place in the project plan. Overdue dates are identified using today in Sri Lanka (Asia/Colombo).">
        @if ($milestones->isEmpty())
            <x-dashboard.empty-state icon="calendar-days" title="No milestones planned yet." :description="$canManage ? 'Add the first milestone below to give this collaboration a clear plan.' : 'Milestones created by the owning Client will appear here.'" />
        @else
            <ol class="space-y-4 p-5 sm:p-6" aria-label="Project milestones">
                @foreach ($milestones as $milestone)
                    <li wire:key="milestone-{{ $milestone->id }}" class="tb-editor-preview rounded-xl border border-slate-200 p-5 dark:border-slate-700">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="tb-muted text-sm">Milestone {{ $loop->iteration }}</p>
                                <h2 class="mt-1 break-words text-lg font-semibold">{{ $milestone->title }}</h2>
                                <p class="tb-muted mt-2">Due <time datetime="{{ $milestone->due_date->toDateString() }}">{{ $milestone->due_date->format('j F Y') }}</time></p>
                                @if ($milestone->isOverdue())
                                    <p class="mt-2 font-semibold text-amber-900 dark:text-amber-200">Overdue <span class="font-normal">— due before today in Sri Lanka.</span></p>
                                @endif
                            </div>
                            @if ($canManage)
                                <flux:button type="button" wire:click="edit({{ $milestone->id }})" wire:loading.attr="disabled" wire:target="edit,save,cancelEdit" :aria-label="'Edit milestone '.$milestone->title" class="self-start shrink-0">Edit milestone</flux:button>
                            @endif
                        </div>
                        @if ($milestone->description !== null && $milestone->description !== '')
                            <p class="mt-4 whitespace-pre-wrap break-words leading-7">{{ $milestone->description }}</p>
                        @endif
                        <div class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700">
                            <x-tasks.progress :progress="\App\Support\TaskProgress::summarize($milestone->tasks_count, $milestone->completed_tasks_count)" :label="'Task progress for '.$milestone->title" />
                            @if (($milestoneTasks = $tasksByMilestone->get($milestone->id, collect()))->isNotEmpty())
                                <ol class="mt-4 space-y-3" aria-label="Tasks for {{ $milestone->title }}">
                                    @foreach ($milestoneTasks as $task)
                                        <x-tasks.card :task="$task" :can-manage="$canManage" :can-change-status="$canChangeTaskStatuses" :statuses="$taskStatuses" />
                                    @endforeach
                                </ol>
                            @elseif ($milestone->tasks_count > 0)
                                <p class="tb-muted mt-3">No tasks match this status filter.</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-dashboard.panel>

    <x-dashboard.panel title="Ungrouped tasks" description="Tasks without a milestone. Due dates still follow the agreed assignment interval.">
        <div class="space-y-4 p-5 sm:p-6">
            <x-tasks.progress :progress="$ungroupedProgress" label="Ungrouped task progress" />
            @if ($ungroupedTasks->isNotEmpty())
                <ol class="space-y-3" aria-label="Tasks without a milestone">
                    @foreach ($ungroupedTasks as $task)
                        <x-tasks.card :task="$task" :can-manage="$canManage" :can-change-status="$canChangeTaskStatuses" :statuses="$taskStatuses" />
                    @endforeach
                </ol>
            @elseif ($ungroupedProgress['total'] > 0)
                <p class="tb-muted">No tasks match this status filter.</p>
            @else
                <p class="tb-muted">{{ $canManage ? 'Add a task below, with or without a milestone.' : 'Tasks created by the owning Client will appear here.' }}</p>
            @endif
        </div>
    </x-dashboard.panel>

    @if ($canManage)
        <form wire:submit="save" class="space-y-5" aria-label="Milestone details" x-data x-on:milestone-editing.window="$nextTick(() => $el.querySelector('input')?.focus())">
            <fieldset wire:loading.attr="disabled" wire:target="save,edit,cancelEdit" class="min-w-0">
                <legend class="sr-only">{{ $editingMilestoneId ? 'Edit milestone' : 'Add milestone' }}</legend>
                <x-dashboard.panel :title="$editingMilestoneId ? 'Edit milestone' : 'Add a milestone'">
                    <div class="space-y-6 p-5 sm:p-6">
                        <flux:input wire:model="form.title" label="Milestone title" description="Up to 150 characters." maxlength="150" required />
                        <flux:textarea wire:model="form.description" label="Description (optional)" description="Plain text, up to 5,000 characters." maxlength="5000" rows="5" />
                        <flux:input wire:model="form.due_date" label="Due date" type="date" :min="$earliestDate" :max="$latestDate" description="Within the assignment date and agreed completion date, including past dates." required />
                    </div>
                </x-dashboard.panel>
            </fieldset>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,edit,cancelEdit">
                    <span wire:loading.remove wire:target="save">{{ $editingMilestoneId ? 'Save changes' : 'Add milestone' }}</span>
                    <span wire:loading wire:target="save" role="status">Saving milestone...</span>
                </flux:button>
                @if ($editingMilestoneId)
                    <flux:button type="button" variant="ghost" wire:click="cancelEdit" wire:loading.attr="disabled" wire:target="save,edit,cancelEdit">Cancel editing</flux:button>
                @endif
                <span role="status" aria-live="polite" wire:loading wire:target="edit">Loading saved milestone...</span>
            </div>
        </form>
    @endif

    @if ($canManage)
        <form wire:submit="saveTask" class="space-y-5" aria-label="Task details" x-data x-on:task-editing.window="$nextTick(() => $el.querySelector('input')?.focus())">
            <fieldset wire:loading.attr="disabled" wire:target="saveTask,editTask,cancelTaskEdit,changeTaskStatus" class="min-w-0">
                <legend class="sr-only">{{ $editingTaskId ? 'Edit task' : 'Add task' }}</legend>
                <x-dashboard.panel :title="$editingTaskId ? 'Edit task' : 'Add a task'" description="Task details are separate from task status. Saving details preserves status and completion time.">
                    <div class="space-y-6 p-5 sm:p-6">
                        <flux:input wire:model="taskForm.title" label="Task title" description="Up to 150 characters." maxlength="150" required />
                        <flux:textarea wire:model="taskForm.description" label="Task description (optional)" description="Plain text, up to 5,000 characters." maxlength="5000" rows="5" />
                        <flux:select wire:model="taskForm.milestone_id" label="Milestone (optional)" description="Tasks attached to a milestone must be due on or before that milestone's due date.">
                            <option value="">No milestone — ungrouped task</option>
                            @foreach ($milestones as $milestone)
                                <option value="{{ $milestone->id }}">{{ $milestone->title }} — due {{ $milestone->due_date->format('j F Y') }}</option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="taskForm.due_date" label="Task due date" type="date" :min="$earliestDate" :max="$latestDate" description="Within the assignment interval and no later than the selected milestone's due date. Past dates are allowed." required />
                    </div>
                </x-dashboard.panel>
            </fieldset>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveTask,editTask,cancelTaskEdit,changeTaskStatus">
                    <span wire:loading.remove wire:target="saveTask">{{ $editingTaskId ? 'Save task changes' : 'Add task' }}</span>
                    <span wire:loading wire:target="saveTask" role="status">Saving task...</span>
                </flux:button>
                @if ($editingTaskId)
                    <flux:button type="button" variant="ghost" wire:click="cancelTaskEdit" wire:loading.attr="disabled" wire:target="saveTask,editTask,cancelTaskEdit,changeTaskStatus">Cancel task editing</flux:button>
                @endif
                <span role="status" aria-live="polite" wire:loading wire:target="editTask">Loading saved task...</span>
            </div>
        </form>
    @endif
</div>
