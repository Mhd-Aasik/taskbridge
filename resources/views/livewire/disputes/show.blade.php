<?php

use App\Actions\Disputes\ResolveDispute;
use App\Actions\Disputes\SubmitDisputeStatement;
use App\Enums\DisputeDecision;
use App\Livewire\Concerns\HandlesDisputeActions;
use App\Models\DisputeCase;
use App\Support\DisputeContext;
use App\Support\DisputeConversation;
use App\Support\TaskProgress;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('Private dispute case')] class extends Component {
    use HandlesDisputeActions, WithPagination;

    #[Locked]
    public int $caseId;
    #[Locked]
    public bool $adminContext = false;
    #[Locked]
    public string $statementToken;
    #[Locked]
    public bool $statementSubmitted = false;
    public $body = '';
    public $resolutionDecision = 'resolved';
    public $rationale = '';
    public $resolutionConfirmed = false;

    public function mount(DisputeCase $dispute, bool $administration = false): void
    {
        $this->caseId = $dispute->id;
        $this->adminContext = $administration;
        $this->statementToken = (string) Str::uuid();
        $this->authorisedContext();
    }

    protected function authorisedContext(): array
    {
        [$user, $project, $assignment, $dispute] = DisputeContext::forCase($this->caseId);
        Gate::forUser($user)->authorize($this->adminContext ? 'viewAdmin' : 'viewParticipant', [$dispute, $assignment, $project]);
        return [$user, $project, $assignment, $dispute];
    }

    public function submitStatement(): void
    {
        $this->authorisedContext();
        $this->resetValidation();
        $statement = $this->performDisputeAction(fn () => app(SubmitDisputeStatement::class)->submit($this->caseId, ['body' => $this->body], $this->statementToken), 'statement');
        if (! $statement) { return; }
        $this->statementSubmitted = true;
        $this->resetPage('discussionPage');
        session()->flash('dispute-saved', 'Statement recorded. Submitted text is preserved in case history.');
    }

    public function startStatement(): void
    {
        [, , , $dispute] = $this->authorisedContext();
        if (! $dispute->isOpen()) { throw ValidationException::withMessages(['statement' => 'This case is closed. New statements are unavailable.']); }
        $this->statementToken = (string) Str::uuid();
        $this->body = '';
        $this->statementSubmitted = false;
        $this->resetValidation();
    }

    public function resolveCase(): void
    {
        [$user, $project, $assignment, $dispute] = $this->authorisedContext();
        Gate::forUser($user)->authorize('resolve', [$dispute, $assignment, $project]);
        $this->resetValidation();
        try {
            $resolution = $this->performDisputeAction(fn () => app(ResolveDispute::class)->resolve($this->caseId, ['decision' => $this->resolutionDecision, 'rationale' => $this->rationale, 'confirmed' => $this->resolutionConfirmed]), 'resolution');
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) { $errors[match ($key) { 'decision' => 'resolutionDecision', 'confirmed' => 'resolutionConfirmed', default => $key }] = $messages; }
            throw ValidationException::withMessages($errors);
        }
        if (! $resolution) { return; }
        session()->flash('dispute-saved', 'Admin decision recorded. The case is closed and its completion blocker is removed. Work and payment history have not changed.');
    }

    public function with(): array
    {
        [$user, $project, $assignment, $dispute] = $this->authorisedContext();
        $pagination = Validator::make(['messagePage' => $this->paginators['messagePage'] ?? request()->query('messagePage', 1), 'discussionPage' => $this->paginators['discussionPage'] ?? request()->query('discussionPage', 1), 'workPage' => $this->paginators['workPage'] ?? request()->query('workPage', 1)], [
            'messagePage' => ['required', 'integer', 'min:1', 'max:10000'],
            'discussionPage' => ['required', 'integer', 'min:1', 'max:10000'], 'workPage' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        abort_if($pagination->fails(), 422, 'Choose discussion, conversation and work pages between 1 and 10,000.');
        $assignment->load(['freelancer:id,name', 'paymentReport.reporter:id,name', 'paymentReport.acknowledgment.acknowledger:id,name']);
        return [
            'project' => $project->load(['client:id,name', 'completer:id,name']),
            'assignment' => $assignment,
            'dispute' => $dispute->load(['opener:id,name', 'resolution.decider:id,name', 'activeRecord']),
            'statements' => $dispute->statements()->with('author:id,name')->reorder()->orderByDesc('submitted_at')->orderByDesc('id')->paginate(10, ['*'], 'discussionPage'),
            'deliverables' => $assignment->deliverables()->with(['milestone:id,title', 'versions.uploader:id,name', 'versions.review.reviewer:id,name'])->paginate(5, ['*'], 'workPage'),
            'taskProgress' => TaskProgress::forAssignment($assignment),
            'canResolve' => Gate::forUser($user)->allows('resolve', [$dispute, $assignment, $project]),
            'decisions' => DisputeDecision::cases(),
            'caseMessages' => $this->adminContext ? DisputeConversation::messages($this->caseId)->paginate(20, ['*'], 'messagePage') : null,
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-5xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0"><p class="tb-eyebrow mb-2">Private dispute #{{ $dispute->id }}</p><h1 class="break-words text-2xl font-bold sm:text-3xl">{{ $dispute->title }}</h1><p class="tb-muted mt-2">Case state: {{ Str::headline($dispute->state()) }}</p></div>
        <flux:button :href="route($adminContext ? 'admin.disputes.index' : 'disputes.index')" wire:navigate>Case history</flux:button>
    </div>
    @unless ($adminContext)<x-projects.cover :project="$project" />@endunless
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('dispute-saved') }}</p>
    <flux:error name="statement" /><flux:error name="resolution" />
    <x-dashboard.panel title="Original case history" description="The original opening text is preserved unchanged.">
        <div class="space-y-4 p-5 sm:p-6"><p class="tb-muted break-words">Opened by {{ $dispute->opener->name }} · <time datetime="{{ $dispute->opened_at->toIso8601String() }}">{{ $dispute->opened_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</p><p class="whitespace-pre-wrap break-words leading-7">{{ $dispute->reason }}</p></div>
    </x-dashboard.panel>
    @if ($dispute->resolution)
        <x-dashboard.panel title="Recorded Admin decision">
            <div class="space-y-4 p-5 sm:p-6"><p class="font-semibold">{{ $dispute->resolution->decision->label() }} by {{ $dispute->resolution->decider->name }}</p><p class="tb-muted"><time datetime="{{ $dispute->resolution->decided_at->toIso8601String() }}">{{ $dispute->resolution->decided_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</p><p class="whitespace-pre-wrap break-words leading-7">{{ $dispute->resolution->rationale }}</p><p class="tb-muted">This decision closes the case. It does not change work, completion or payment history, transfer money, or issue a refund.</p></div>
        </x-dashboard.panel>
    @else
        <p class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">An active case blocks new milestone and project completion. Existing completion is preserved. Normal authorised work and payment tracking continue.</p>
    @endif

    <x-dashboard.panel title="Private case discussion" description="Newest statements first. Submitted text is append-only.">
        @if ($statements->isEmpty())
            <x-dashboard.empty-state icon="chat-bubble-left-right" title="No statements yet." description="Participants can add relevant information, and Admins can request clarification while the case is open." />
        @else
            <ol class="divide-y divide-slate-200 dark:divide-slate-700" aria-label="Case statements">
                @foreach ($statements as $statement)
                    <li class="space-y-3 p-5 sm:p-6" wire:key="statement-{{ $statement->id }}"><p class="break-words font-semibold">{{ $statement->kind->label() }} · {{ $statement->author->name }}</p><p class="tb-muted text-sm"><time datetime="{{ $statement->submitted_at->toIso8601String() }}">{{ $statement->submitted_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</p><p class="whitespace-pre-wrap break-words leading-7">{{ $statement->body }}</p></li>
                @endforeach
            </ol>
        @endif
    </x-dashboard.panel>
    {{ $statements->links() }}
    @if ($dispute->isOpen())
        @if ($statementSubmitted)
            <flux:button type="button" wire:click="startStatement" wire:loading.attr="disabled" wire:target="startStatement,submitStatement">Start another statement</flux:button>
        @else
            <form wire:submit="submitStatement" class="space-y-5" aria-label="{{ $adminContext ? 'Admin clarification' : 'Add a case statement' }}">
                <flux:textarea wire:model="body" :label="$adminContext ? 'Clarification request' : 'Your statement'" description="Meaningful plain text, up to 5,000 characters. Submitted statements cannot be edited." maxlength="5000" rows="6" required />
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="submitStatement">{{ $adminContext ? 'Request clarification' : 'Add statement' }}</flux:button>
                <p role="status" aria-live="polite" wire:loading wire:target="submitStatement">Saving statement...</p>
            </form>
        @endif
    @endif

    @if ($adminContext && $dispute->isOpen())
        @if ($canResolve)
            <div x-data>
                <flux:button type="button" x-on:click="$refs.resolutionDialog.showModal()">Record Admin decision</flux:button>
                <dialog x-ref="resolutionDialog" aria-labelledby="resolve-case-heading" aria-describedby="resolve-case-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
                    <h2 id="resolve-case-heading" class="text-lg font-semibold">Record a permanent case decision</h2><p id="resolve-case-explanation" class="tb-muted mt-3 leading-7">Resolving or dismissing closes this case and removes its completion blocker. It does not complete or reopen work, alter payment records, transfer money, or issue refunds.</p>
                    <form wire:submit="resolveCase" class="mt-5 space-y-5">
                        <flux:error name="resolution" />
                        <flux:select wire:model="resolutionDecision" label="Admin decision">@foreach ($decisions as $decision)<option value="{{ $decision->value }}">{{ $decision->label() }}</option>@endforeach</flux:select>
                        <flux:textarea wire:model="rationale" label="Decision rationale" description="Required meaningful plain text, up to 5,000 characters. Visible to both participants." maxlength="5000" rows="6" required />
                        <flux:checkbox wire:model="resolutionConfirmed" label="I confirm this permanent decision and rationale." /><flux:error name="resolutionConfirmed" />
                        <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" x-on:click="$refs.resolutionDialog.close()" wire:loading.attr="disabled" wire:target="resolveCase" autofocus>Keep case open</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="resolveCase">Confirm Admin decision</flux:button></div>
                        <p role="status" aria-live="polite" wire:loading wire:target="resolveCase">Recording decision...</p>
                    </form>
                </dialog>
            </div>
        @else
            <p role="status" class="tb-muted">You cannot resolve a case involving your own account. Another authorised Admin must decide it.</p>
        @endif
    @endif

    @if ($caseMessages !== null)<x-messages.dispute-context :messages="$caseMessages" />@endif
    <x-disputes.context :project="$project" :assignment="$assignment" :dispute="$dispute" :task-progress="$taskProgress" :deliverables="$deliverables" :admin-context="$adminContext" />
</div>
