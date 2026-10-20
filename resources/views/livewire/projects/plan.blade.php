<?php

use App\Actions\Milestones\SaveMilestone;
use App\Livewire\Forms\MilestoneForm;
use App\Models\Project;
use App\Support\MilestoneRules;
use App\Support\ProjectPlanning;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Project plan')] class extends Component {
    public MilestoneForm $form;

    #[Locked]
    public int $projectId;

    #[Locked]
    public ?int $editingMilestoneId = null;

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

    public function with(): array
    {
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);

        return [
            'project' => $project,
            'assignment' => $assignment,
            'milestones' => $assignment->milestones()->get(),
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
        <p>Plan milestone dates from <time datetime="{{ $earliestDate }}">{{ $assignment->assigned_at->setTimezone('Asia/Colombo')->format('j F Y') }}</time> through <time datetime="{{ $latestDate }}">{{ $assignment->agreed_deadline->format('j F Y') }}</time>, inclusive.</p>
        <p class="mt-2">Past dates within this interval are allowed so overdue work remains manageable. Planning does not change the agreed completion date or project status.</p>
        @if (! $canManage)
            <p class="mt-3 font-medium">{{ $isClientOwner ? 'This plan is read-only. Milestones can be changed only while the project is in progress.' : 'You can read this shared plan. Only the owning Client can create or edit milestones while the project is in progress.' }}</p>
        @endif
    </div>

    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('milestone-saved') }}</p>
    <flux:error name="plan" />

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
                    </li>
                @endforeach
            </ol>
        @endif
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
</div>
