<?php

use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\Project;
use App\Support\ProjectPlanning;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

new #[Layout('components.layouts.app'), Title('Deliverable history')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $projectId;

    #[Locked]
    public int $deliverableId;

    #[Locked]
    public string $submissionId;

    #[Locked]
    public ?int $reviewVersionId = null;

    public $reviewRemarks = '';

    public $reviewConfirmed = false;

    public $submission_note = '';

    public $file = null;

    public function mount(Project $project, Deliverable $deliverable): void
    {
        $this->projectId = $project->id;
        $this->deliverableId = $deliverable->id;
        $this->submissionId = (string) Str::uuid();
        [, , , $deliverable] = $this->authorisedContext();
        $this->reviewVersionId = $deliverable->latestVersion?->id;
    }

    protected function authorisedContext(): array
    {
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);
        $deliverable = $assignment->deliverables()->findOrFail($this->deliverableId);
        Gate::forUser($user)->authorize('view', [$deliverable, $assignment, $project]);

        return [$user, $project, $assignment, $deliverable];
    }

    public function save(): void
    {
        $this->authorisedContext();
        $this->resetValidation();

        try {
            app(SubmitDeliverable::class)->submit($this->projectId, $this->deliverableId, [
                'submission_note' => $this->submission_note,
            ], $this->file, $this->submissionId);
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('deliverables', 'We could not submit this version. Your note has been kept and previous versions are unchanged. Please try again.');

            return;
        }

        session()->flash('deliverable-saved', 'New version submitted. Previous versions remain available. Submission does not mean Client approval or project acceptance.');
        $this->redirectRoute('projects.deliverables.show', ['project' => $this->projectId, 'deliverable' => $this->deliverableId], navigate: true);
    }

    public function refreshReviewTarget(): void
    {
        [$user, $project, $assignment, $deliverable] = $this->authorisedContext();
        $version = $deliverable->latestVersion()->firstOrFail();
        Gate::forUser($user)->authorize('review', [$version, $deliverable, $assignment, $project]);
        $this->reviewVersionId = $version->id;
        $this->reviewConfirmed = false;
        $this->resetValidation(['review', 'confirmed']);
    }

    public function approveVersion(): void
    {
        $this->reviewVersion('approve');
    }

    public function requestChanges(): void
    {
        $this->reviewVersion('requestChanges');
    }

    protected function reviewVersion(string $method): void
    {
        $this->authorisedContext();
        abort_unless($this->reviewVersionId !== null, 404);
        $this->resetValidation();

        try {
            app(ReviewDeliverableVersion::class)->{$method}($this->projectId, $this->deliverableId, $this->reviewVersionId, [
                'remarks' => $this->reviewRemarks,
                'confirmed' => $this->reviewConfirmed,
            ]);
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('review', 'We could not save this review. Your remarks have been kept. Please try again.');

            return;
        }

        session()->flash('deliverable-review-saved', $method === 'approve' ? 'Version approved. Project completion and payment have not changed.' : 'Changes requested. The Freelancer can submit a new version with a response note.');
    }

    public function with(): array
    {
        [$user, $project, $assignment, $deliverable] = $this->authorisedContext();
        $deliverable->load(['milestone:id,title,completed_at', 'latestVersion.review.reviewer:id,name']);
        $latest = $deliverable->latestVersion;

        return [
            'project' => $project,
            'deliverable' => $deliverable,
            'versions' => $deliverable->versions()->with(['uploader:id,name', 'review.reviewer:id,name'])->orderByDesc('version_number')->orderByDesc('id')->get(),
            'canUpload' => Gate::forUser($user)->allows('uploadVersion', [$deliverable, $assignment, $project]),
            'canReview' => $latest && ! $latest->review && ! $deliverable->milestone?->isCompleted() && $project->status === ProjectStatus::InProgress && Gate::forUser($user)->allows('review', [$latest, $deliverable, $assignment, $project]),
            'reviewTargetCurrent' => $latest?->id === $this->reviewVersionId,
            'reviewState' => $deliverable->reviewState(),
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="tb-eyebrow mb-2">Private deliverable history</p>
            <h1 class="break-words text-2xl font-bold tracking-tight sm:text-3xl">{{ $deliverable->title }}</h1>
            <p class="tb-muted mt-2 break-words">{{ $project->title }}</p>
            <p class="tb-muted mt-1 break-words">{{ $deliverable->milestone ? 'Milestone: '.$deliverable->milestone->title : 'Not linked to a milestone' }}</p>
        </div>
        <flux:button :href="route('projects.deliverables.index', $project)" icon="arrow-left" class="self-start shrink-0" wire:navigate>All deliverables</flux:button>
    </div>

    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('deliverable-saved') }}</p>
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('deliverable-review-saved') }}</p>
    <flux:error name="deliverables" />
    <flux:error name="review" />

    @if ($deliverable->description !== null && $deliverable->description !== '')
        <x-dashboard.panel title="Deliverable description">
            <p class="whitespace-pre-wrap break-words p-5 leading-7 sm:p-6">{{ $deliverable->description }}</p>
        </x-dashboard.panel>
    @endif

    <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        @if ($deliverable->milestone?->isCompleted())
            <p class="mb-3 font-medium">This milestone is completed. Its work is locked; historical versions and downloads remain available.</p>
        @endif
        <p>Each submitted version and completed review is retained unchanged. Version approval does not itself complete the project or confirm payment.</p>
        @if ($reviewState === 'approved')
            <p class="mt-3 font-medium">This deliverable is approved. Further versions are closed; other deliverables are unaffected.</p>
        @elseif ($reviewState === 'changes_requested')
            <p class="mt-3 font-medium">Changes requested. Review the Client’s remarks below. The assigned Freelancer can upload a new version with a response in the submission note while the project is in progress.</p>
        @elseif ($reviewState === 'awaiting_review')
            <p class="mt-3 font-medium">Awaiting Client review. The assigned Freelancer may submit a newer version before review while the project is in progress.</p>
        @endif
        @if (! $canUpload && $reviewState !== 'approved')
            <p class="mt-3">New versions can be uploaded only by the assigned Freelancer while the project is in progress.</p>
        @endif
    </div>

    @if ($canReview)
        <x-dashboard.panel title="Review latest version" description="Only the latest unreviewed version can receive a review.">
            <div class="space-y-5 p-5 sm:p-6">
                @if (! $reviewTargetCurrent)
                    <p role="status" class="tb-muted leading-7">A newer version has been submitted. Read its file and note before starting a new review. Your entered remarks have been kept.</p>
                    <flux:button type="button" wire:click="refreshReviewTarget" wire:loading.attr="disabled" wire:target="refreshReviewTarget">Review latest submission</flux:button>
                @else
                    <p class="font-medium">Reviewing version {{ $deliverable->latestVersion->version_number }}</p>
                    <flux:textarea wire:model="reviewRemarks" name="remarks" label="Review remarks" description="Required when requesting changes; optional for approval. Plain text, up to 5,000 characters." maxlength="5000" rows="5" />
                    <flux:error name="remarks" />
                    <div class="flex flex-wrap gap-3" x-data>
                        <flux:button type="button" wire:click="requestChanges" wire:loading.attr="disabled" wire:target="requestChanges,approveVersion">Request changes</flux:button>
                        <flux:button type="button" variant="primary" x-on:click="$refs.approvalDialog.showModal()" wire:loading.attr="disabled" wire:target="requestChanges,approveVersion">Approve version</flux:button>
                        <dialog x-ref="approvalDialog" aria-labelledby="approve-version-heading" aria-describedby="approve-version-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
                            <h2 id="approve-version-heading" class="text-lg font-semibold">Approve version {{ $deliverable->latestVersion->version_number }}?</h2>
                            <p id="approve-version-explanation" class="tb-muted mt-3 leading-7">Approval permanently records your review and closes further uploads to this deliverable. It does not mark the whole project completed or paid. Other deliverables remain separate.</p>
                            <form wire:submit="approveVersion" class="mt-5 space-y-5">
                                <flux:error name="review" />
                                <flux:error name="remarks" />
                                <flux:checkbox wire:model="reviewConfirmed" label="I confirm approval of this version." />
                                <flux:error name="confirmed" />
                                <div class="flex flex-wrap justify-end gap-3">
                                    <flux:button type="button" x-on:click="$refs.approvalDialog.close()" wire:loading.attr="disabled" wire:target="approveVersion" autofocus>Keep reviewing</flux:button>
                                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="approveVersion">Confirm approval</flux:button>
                                </div>
                                <p role="status" aria-live="polite" wire:loading wire:target="approveVersion">Saving approval...</p>
                            </form>
                        </dialog>
                    </div>
                    <p role="status" aria-live="polite" wire:loading wire:target="requestChanges">Saving change request...</p>
                @endif
            </div>
        </x-dashboard.panel>
    @endif

    <x-dashboard.panel title="Complete version history" description="Newest versions appear first. Submission times are shown in Sri Lanka (Asia/Colombo).">
        @if ($versions->isEmpty())
            <x-dashboard.empty-state icon="document-text" title="No versions yet." description="Submitted file versions will appear here." />
        @else
            <ol class="space-y-4 p-5 sm:p-6" aria-label="Deliverable versions">
                @foreach ($versions as $version)
                    <li wire:key="deliverable-version-{{ $version->id }}" class="tb-editor-preview rounded-xl border border-slate-200 p-5 dark:border-slate-700">
                        <x-deliverables.version :version="$version" :project="$project" :deliverable="$deliverable" :latest="$loop->first" />
                    </li>
                @endforeach
            </ol>
        @endif
    </x-dashboard.panel>

    @if ($canUpload)
        <x-deliverables.upload-form existing />
    @endif
</div>
