<?php

use App\Actions\Proposals\SubmitProposal;
use App\Livewire\Forms\ProposalSubmissionForm;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ProposalSubmissionRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Submit a proposal')] class extends Component {
    public ProposalSubmissionForm $form;

    #[Locked]
    public int $projectId;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->context();
        abort_unless(Project::query()->discoverable()->whereKey($this->projectId)->exists(), 404);
    }

    public function submit(): void
    {
        try {
            $result = app(SubmitProposal::class)->submit($this->projectId, $this->form->details());
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[in_array($key, ProposalSubmissionRules::FIELDS, true) ? 'form.'.$key : $key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        session()->flash('proposal-submission', $result['created']
            ? 'Proposal submitted. This does not assign the project or guarantee acceptance.'
            : 'Your proposal is already on record. The original details and submission time have been kept.');
        $this->redirectRoute('freelancer.proposals.show', $result['proposal'], navigate: true);
    }

    protected function context(): array
    {
        $user = User::query()->find(Auth::id());
        abort_unless($user, 403);
        $project = Project::query()->findOrFail($this->projectId);
        Gate::forUser($user)->authorize('create', [Proposal::class, $project]);

        return [$user, $project];
    }

    public function with(): array
    {
        [$user, $project] = $this->context();

        return [
            'project' => $project->load('category:id,name'),
            'existingProposal' => $user->submittedProposals()->where('project_id', $project->id)->first(['id']),
            'unavailableReason' => ProposalSubmissionRules::unavailableReason($project),
            'earliestDeadline' => ProposalSubmissionRules::earliestDeadline(),
            'latestDeadline' => $project->calendarDeadline()?->toDateString(),
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="tb-eyebrow mb-2">Freelancer workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Submit a proposal</h1>
            <div class="mt-4 flex items-start gap-4">
                <x-projects.cover :project="$project" compact />
                <div class="min-w-0">
                    <p class="break-words font-medium">{{ $project->title }}</p>
                    <p class="tb-muted mt-1">{{ $project->category->name }}</p>
                </div>
            </div>
        </div>
        <flux:button :href="route('freelancer.projects.index')" class="self-start shrink-0" wire:navigate>Browse projects</flux:button>
    </div>

    <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        <p>Submitting a proposal does not assign the project or guarantee acceptance. Your freelancer profile can remain private.</p>
    </div>

    @if ($existingProposal)
        <x-dashboard.panel title="You have already submitted a proposal">
            <div class="space-y-4 p-5 sm:p-6">
                <p class="tb-muted">Your original proposal remains unchanged. You can read its details and status in My proposals.</p>
                <flux:button :href="route('freelancer.proposals.show', $existingProposal)" variant="primary" wire:navigate>View your proposal</flux:button>
            </div>
        </x-dashboard.panel>
    @else
        @if ($unavailableReason)
            <p role="alert" class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">{{ $unavailableReason }}</p>
        @endif
        <flux:error name="project" />
        <x-dashboard.panel title="The advertised project">
            <dl class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6">
                <div><dt class="tb-muted text-sm">Advertised fixed budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $project->formattedBudget() }}</dd></div>
                <div><dt class="tb-muted text-sm">Project deadline</dt><dd class="mt-1 font-medium">{{ $project->calendarDeadline()?->format('j F Y') }}</dd></div>
            </dl>
        </x-dashboard.panel>

        <form wire:submit="submit" class="space-y-6">
            <fieldset @disabled($unavailableReason !== null) wire:loading.attr="disabled" wire:target="submit" class="min-w-0">
                <legend class="sr-only">Your proposed offer</legend>
                <x-dashboard.panel title="Your proposal">
                    <div class="space-y-6 p-5 sm:p-6">
                        <flux:textarea wire:model="form.cover_letter" label="Cover letter" description="Explain your approach and relevant experience. Plain text, up to 5,000 characters." maxlength="5000" rows="9" required />
                        <div class="grid items-start gap-6 sm:grid-cols-2">
                            <flux:input wire:model="form.proposed_budget" label="Your proposed fixed budget (LKR)" description:trailing="Your offer may differ from the advertised budget. LKR 1–9,999,999,999.99; up to 2 decimals." inputmode="decimal" maxlength="13" required />
                            <flux:input wire:model="form.proposed_deadline" label="Your proposed completion date" type="date" :min="$earliestDeadline" :max="$latestDeadline" description:trailing="Tomorrow or later in Sri Lanka, up to the project deadline." required />
                        </div>
                    </div>
                </x-dashboard.panel>
            </fieldset>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary" :disabled="$unavailableReason !== null" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">Submit proposal</span>
                    <span wire:loading wire:target="submit" role="status">Submitting proposal...</span>
                </flux:button>
                <flux:button :href="route('freelancer.proposals.index')" variant="ghost" wire:navigate>My proposals</flux:button>
            </div>
        </form>
    @endif
</div>
