<?php

use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\Project;
use App\Support\ProjectPayment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

new #[Layout('components.layouts.app'), Title('External payment')] class extends Component {
    #[Locked]
    public int $projectId;

    #[Locked]
    public string $submissionId;

    #[Locked]
    public ?int $reportId = null;

    public $reportMethod = 'bank_transfer';

    public $reportPaidOn = '';

    public $reportReference = '';

    public $reportNote = '';

    public $reportConfirmed = false;

    public $receiptNote = '';

    public $receiptConfirmed = false;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->submissionId = (string) Str::uuid();
        $this->reportPaidOn = CarbonImmutable::today('Asia/Colombo')->toDateString();
        [, , $assignment] = ProjectPayment::context($this->projectId);
        $this->reportId = $assignment->paymentReport()->value('id');
    }

    public function refreshPayment(): void
    {
        [, , $assignment] = ProjectPayment::context($this->projectId);
        $this->reportId = $assignment->paymentReport()->value('id');
        $this->receiptConfirmed = false;
        $this->resetValidation();
    }

    public function recordPayment(): void
    {
        $this->resetValidation();
        try {
            $report = app(ReportPayment::class)->report($this->projectId, [
                'method' => $this->reportMethod, 'paid_on' => $this->reportPaidOn,
                'reference' => $this->reportReference, 'note' => $this->reportNote,
                'confirmed' => $this->reportConfirmed,
            ], $this->submissionId);
        } catch (ValidationException $exception) {
            $this->validationFeedback($exception, ['method' => 'reportMethod', 'paid_on' => 'reportPaidOn', 'reference' => 'reportReference', 'note' => 'reportNote', 'confirmed' => 'reportConfirmed']);
        } catch (AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('payment', 'We could not record this payment. Your entered details have been kept. Please try again.');

            return;
        }
        $this->reportId = $report->id;
        session()->flash('payment-saved', 'Full external payment recorded as reported by the Client. Receipt is awaiting the Freelancer’s acknowledgment. Work and completion records have not changed.');
    }

    public function acknowledgeReceipt(): void
    {
        [$user, , $assignment] = ProjectPayment::context($this->projectId);
        abort_unless($user->isFreelancer() && $assignment->freelancer_id === $user->id, 403);
        $this->resetValidation();
        if ($this->reportId === null) {
            throw ValidationException::withMessages(['receipt' => 'Review an existing Client payment report before acknowledging receipt. Refresh payment status to check for a report.']);
        }
        try {
            app(AcknowledgePayment::class)->acknowledge($this->projectId, $this->reportId, ['note' => $this->receiptNote, 'confirmed' => $this->receiptConfirmed]);
        } catch (ValidationException $exception) {
            $this->validationFeedback($exception, ['note' => 'receiptNote', 'confirmed' => 'receiptConfirmed']);
        } catch (AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('receipt', 'We could not record receipt acknowledgment. Your note has been kept. Please try again.');

            return;
        }
        session()->flash('payment-saved', 'The assigned Freelancer acknowledged receipt of the reported full payment. Work and completion records have not changed.');
    }

    protected function validationFeedback(ValidationException $exception, array $fields): never
    {
        $errors = [];
        foreach ($exception->errors() as $key => $messages) {
            $errors[$fields[$key] ?? (in_array($key, ['payment', 'receipt'], true) ? $key : 'payment')] = $messages;
        }
        throw ValidationException::withMessages($errors);
    }

    public function with(): array
    {
        [$user, $project, $assignment] = ProjectPayment::context($this->projectId);
        $report = $assignment->paymentReport()->with(['reporter:id,name', 'acknowledgment.acknowledger:id,name'])->first();

        return [
            'project' => $project,
            'assignment' => $assignment->load('activeDispute'),
            'paymentReport' => $report,
            'methods' => PaymentMethod::cases(),
            'isClientOwner' => $user->isClient() && $project->client_id === $user->id,
            'canReport' => ! $report && in_array($project->status, [ProjectStatus::InProgress, ProjectStatus::Completed], true) && Gate::forUser($user)->allows('create', [PaymentReport::class, $assignment, $project]),
            'canAcknowledge' => $report && ! $report->acknowledgment && Gate::forUser($user)->allows('create', [PaymentAcknowledgment::class, $report, $assignment, $project]),
            'earliestDate' => $assignment->assigned_at->setTimezone('Asia/Colombo')->toDateString(),
            'today' => CarbonImmutable::today('Asia/Colombo')->toDateString(),
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="tb-eyebrow mb-2">Private project workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">External payment</h1>
            <p class="mt-2 break-words font-medium">{{ $project->title }}</p>
            <p class="tb-muted mt-1">Project status: {{ Str::headline($project->status->value) }}</p>
        </div>
        <flux:button :href="$isClientOwner ? route('client.projects.show', $project) : route('freelancer.assignments.show', $assignment)" wire:navigate>{{ $isClientOwner ? 'Project review' : 'Assigned project' }}</flux:button>
    </div>
    <x-projects.cover :project="$project" />
    <x-disputes.notice :project="$project" :assignment="$assignment" />
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('payment-saved') }}</p>
    <flux:error name="payment" />
    <flux:error name="receipt" />

    <div class="tb-editor-notice space-y-3 rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        <p>Record one full payment made outside TaskBridge. TaskBridge does not transfer money or verify the external transaction.</p>
        <p>Client payment reporting and Freelancer receipt acknowledgment are separate. Neither changes tasks, deliverable approvals, agreed terms or project completion.</p>
        <p>Reports and acknowledgments are permanent. Installments, editing, corrections and refunds are unavailable in this step.</p>
    </div>

    <x-dashboard.panel title="Payment tracking">
        <dl class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6">
            <div><dt class="tb-muted">Full agreed amount</dt><dd class="mt-1 text-lg font-semibold tabular-nums">{{ $assignment->formattedBudget() }}</dd></div>
            <div><dt class="tb-muted">Payment status</dt><dd class="mt-1 font-semibold">{{ $paymentReport ? 'Paid — reported by the Client' : 'Pending — no Client payment report' }}</dd></div>
            <div class="sm:col-span-2"><dt class="tb-muted">Receipt acknowledgment</dt><dd class="mt-1">{{ $paymentReport?->acknowledgment ? 'Acknowledged by the assigned Freelancer' : ($paymentReport ? 'Awaiting Freelancer acknowledgment' : 'Not available until the Client reports payment') }}</dd></div>
        </dl>
    </x-dashboard.panel>

    @if ($paymentReport)
        <x-dashboard.panel title="Client payment report">
            <div class="space-y-5 p-5 sm:p-6">
                <dl class="grid gap-5 sm:grid-cols-2">
                    <div><dt class="tb-muted">Reported full amount</dt><dd class="mt-1 font-semibold tabular-nums">{{ $paymentReport->formattedAmount() }}</dd></div>
                    <div><dt class="tb-muted">Method</dt><dd class="mt-1">{{ $paymentReport->method->label() }}</dd></div>
                    <div><dt class="tb-muted">Paid on (calendar date)</dt><dd class="mt-1"><time datetime="{{ $paymentReport->paid_on->toDateString() }}">{{ $paymentReport->paid_on->format('j F Y') }}</time></dd></div>
                    <div><dt class="tb-muted">Reference</dt><dd class="mt-1 break-words">{{ $paymentReport->reference ?? 'No reference provided.' }}</dd></div>
                    <div><dt class="tb-muted">Reported by</dt><dd class="mt-1 break-words">{{ $paymentReport->reporter->name }}</dd></div>
                    <div><dt class="tb-muted">Reported (Sri Lanka)</dt><dd class="mt-1"><time datetime="{{ $paymentReport->reported_at->toIso8601String() }}">{{ $paymentReport->reported_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</dd></div>
                </dl>
                @if ($paymentReport->note !== null)
                    <div><p class="tb-muted">Client note</p><p class="mt-1 whitespace-pre-wrap break-words leading-7">{{ $paymentReport->note }}</p></div>
                @endif
            </div>
        </x-dashboard.panel>
        @if ($paymentReport->acknowledgment)
            <x-dashboard.panel title="Freelancer receipt acknowledgment">
                <div class="space-y-4 p-5 sm:p-6">
                    <p class="break-words font-medium">Acknowledged by {{ $paymentReport->acknowledgment->acknowledger->name }}</p>
                    <p class="tb-muted">Acknowledged <time datetime="{{ $paymentReport->acknowledgment->acknowledged_at->toIso8601String() }}">{{ $paymentReport->acknowledgment->acknowledged_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</p>
                    @if ($paymentReport->acknowledgment->note !== null)
                        <p class="whitespace-pre-wrap break-words leading-7">{{ $paymentReport->acknowledgment->note }}</p>
                    @endif
                </div>
            </x-dashboard.panel>
        @endif
    @endif

    @if ($canReport)
        <div x-data>
            <flux:button type="button" variant="primary" x-on:click="$refs.reportDialog.showModal()">Report external payment</flux:button>
            <dialog x-ref="reportDialog" aria-labelledby="report-payment-heading" aria-describedby="report-payment-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
                <h2 id="report-payment-heading" class="text-lg font-semibold">Report full external payment</h2>
                <p id="report-payment-explanation" class="tb-muted mt-3 leading-7">Record only a full payment you have already made outside TaskBridge. This record cannot be edited and does not acknowledge receipt on the Freelancer’s behalf.</p>
                <form wire:submit="recordPayment" class="mt-5 space-y-5">
                    <flux:error name="payment" />
                    <div><p class="tb-muted">Read-only full agreed amount</p><output class="mt-1 block text-lg font-semibold tabular-nums">{{ $assignment->formattedBudget() }}</output></div>
                    <flux:select wire:model="reportMethod" label="Payment method" required>
                        @foreach ($methods as $method)<option value="{{ $method->value }}">{{ $method->label() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="reportPaidOn" label="Paid on" type="date" :min="$earliestDate" :max="$today" description="From the assignment date through today in Sri Lanka. Payment may be after the agreed work deadline." required />
                    <flux:input wire:model="reportReference" label="Reference (optional)" description="Up to 150 characters." maxlength="150" />
                    <flux:textarea wire:model="reportNote" label="Payment note (optional)" description="Plain text, up to 2,000 characters." maxlength="2000" rows="4" />
                    <flux:checkbox wire:model="reportConfirmed" label="I confirm I made this full external payment and want to record it permanently." />
                    <flux:error name="reportConfirmed" />
                    <div class="flex flex-wrap justify-end gap-3">
                        <flux:button type="button" x-on:click="$refs.reportDialog.close()" wire:loading.attr="disabled" wire:target="recordPayment" autofocus>Keep pending</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="recordPayment">Confirm payment report</flux:button>
                    </div>
                    <p role="status" aria-live="polite" wire:loading wire:target="recordPayment">Recording payment report...</p>
                </form>
            </dialog>
        </div>
    @endif
    @if ($canAcknowledge && $reportId === $paymentReport->id)
        <div x-data>
            <flux:button type="button" variant="primary" x-on:click="$refs.receiptDialog.showModal()">Acknowledge receipt</flux:button>
            <dialog x-ref="receiptDialog" aria-labelledby="acknowledge-heading" aria-describedby="acknowledge-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
                <h2 id="acknowledge-heading" class="text-lg font-semibold">Acknowledge full payment receipt?</h2>
                <p id="acknowledge-explanation" class="tb-muted mt-3 leading-7">Confirm only if you received the full payment reported below. This acknowledgment is permanent and does not complete the project.</p>
                <dl class="mt-4 space-y-3">
                    <div><dt class="tb-muted">Reported amount</dt><dd class="font-semibold tabular-nums">{{ $paymentReport->formattedAmount() }}</dd></div>
                    <div><dt class="tb-muted">Method and date</dt><dd>{{ $paymentReport->method->label() }} · {{ $paymentReport->paid_on->format('j F Y') }}</dd></div>
                    <div><dt class="tb-muted">Reference</dt><dd class="break-words">{{ $paymentReport->reference ?? 'No reference provided.' }}</dd></div>
                </dl>
                <form wire:submit="acknowledgeReceipt" class="mt-5 space-y-5">
                    <flux:error name="receipt" />
                    <flux:textarea wire:model="receiptNote" label="Receipt note (optional)" description="Plain text, up to 2,000 characters." maxlength="2000" rows="4" />
                    <flux:checkbox wire:model="receiptConfirmed" label="I confirm I received the full reported payment." />
                    <flux:error name="receiptConfirmed" />
                    <div class="flex flex-wrap justify-end gap-3">
                        <flux:button type="button" x-on:click="$refs.receiptDialog.close()" wire:loading.attr="disabled" wire:target="acknowledgeReceipt" autofocus>Keep awaiting acknowledgment</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="acknowledgeReceipt">Confirm receipt acknowledgment</flux:button>
                    </div>
                    <p role="status" aria-live="polite" wire:loading wire:target="acknowledgeReceipt">Recording receipt acknowledgment...</p>
                </form>
            </dialog>
        </div>
    @elseif ($canAcknowledge)
        <p class="tb-muted">A Client report is now available. Review its details before acknowledging receipt.</p>
    @endif
    <flux:button type="button" wire:click="refreshPayment" wire:loading.attr="disabled" wire:target="refreshPayment,recordPayment,acknowledgeReceipt">Refresh payment status</flux:button>
    <div class="flex flex-wrap gap-3">
        <flux:button :href="route('projects.plan', $project)" wire:navigate>Project plan</flux:button>
        <flux:button :href="route('projects.completion', $project)" wire:navigate>Project completion</flux:button>
    </div>
</div>
