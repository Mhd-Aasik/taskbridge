<?php

use App\Actions\Deliverables\SubmitDeliverable;
use App\Models\Deliverable;
use App\Models\Project;
use App\Support\DeliverableReviewSummary;
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
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

new #[Layout('components.layouts.app'), Title('Project deliverables')] class extends Component
{
    use WithFileUploads, WithPagination;

    #[Locked]
    public int $projectId;

    #[Locked]
    public string $submissionId;

    public $title = '';

    public $description = '';

    public $milestone_id = '';

    public $submission_note = '';

    public $file = null;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->submissionId = (string) Str::uuid();
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);
        Gate::forUser($user)->authorize('viewAny', [Deliverable::class, $assignment, $project]);
    }

    public function save(): void
    {
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);
        Gate::forUser($user)->authorize('viewAny', [Deliverable::class, $assignment, $project]);
        $this->resetValidation();

        try {
            $version = app(SubmitDeliverable::class)->submit($this->projectId, null, [
                'title' => $this->title,
                'description' => $this->description,
                'milestone_id' => $this->milestone_id,
                'submission_note' => $this->submission_note,
            ], $this->file, $this->submissionId);
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('deliverables', 'We could not submit this file. Your entered details have been kept. Please try again.');

            return;
        }

        session()->flash('deliverable-saved', 'Deliverable submitted. Uploading a file does not mean Client approval or project acceptance.');
        $this->redirectRoute('projects.deliverables.show', ['project' => $this->projectId, 'deliverable' => $version->deliverable_id], navigate: true);
    }

    public function with(): array
    {
        [$user, $project, $assignment] = ProjectPlanning::context($this->projectId);
        Gate::forUser($user)->authorize('viewAny', [Deliverable::class, $assignment, $project]);

        return [
            'project' => $project,
            'assignment' => $assignment,
            'deliverables' => $assignment->deliverables()->with(['milestone:id,title', 'latestVersion.uploader:id,name', 'latestVersion.review.reviewer:id,name'])->withCount('versions')->reorder()->latest('id')->paginate(10),
            'reviewSummary' => DeliverableReviewSummary::forAssignment($assignment),
            'milestones' => $assignment->milestones()->get(['id', 'title']),
            'canUpload' => Gate::forUser($user)->allows('create', [Deliverable::class, $assignment, $project]),
            'isClientOwner' => $user->isClient() && $project->client_id === $user->id,
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="tb-eyebrow mb-2">Private project workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Project deliverables</h1>
            <p class="mt-2 break-words font-medium">{{ $project->title }}</p>
            <p class="tb-muted mt-1">Status: {{ Str::headline($project->status->value) }}</p>
        </div>
        <flux:button :href="$isClientOwner ? route('client.projects.show', $project) : route('freelancer.assignments.show', $assignment)" icon="arrow-left" class="self-start shrink-0" wire:navigate>{{ $isClientOwner ? 'Project review' : 'Assigned project' }}</flux:button>
    </div>

    <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        <p>Files and reviews are private to the owning Client and assigned Freelancer. Every version is retained. Version approval is separate from task progress, project completion, and payment.</p>
        @if (! $canUpload)
            <p class="mt-3 font-medium">{{ $isClientOwner ? 'You can read and download submitted work. Only the assigned Freelancer can upload files while the project is in progress.' : 'Deliverable history is read-only because this project is no longer in progress.' }}</p>
        @endif
    </div>

    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('deliverable-saved') }}</p>
    <flux:error name="deliverables" />

    <x-dashboard.panel title="Deliverable review summary" description="Based on each deliverable’s latest version, across all pages.">
        <dl class="grid grid-cols-2 gap-5 p-5 sm:grid-cols-4 sm:p-6">
            <div><dt class="tb-muted text-sm">Total deliverables</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $reviewSummary['total'] }}</dd></div>
            <div><dt class="tb-muted text-sm">Approved</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $reviewSummary['approved'] }}</dd></div>
            <div><dt class="tb-muted text-sm">Awaiting review</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $reviewSummary['awaiting_review'] }}</dd></div>
            <div><dt class="tb-muted text-sm">Changes requested</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $reviewSummary['changes_requested'] }}</dd></div>
        </dl>
    </x-dashboard.panel>

    <x-dashboard.panel title="Submitted deliverables">
        @if ($deliverables->isEmpty())
            <x-dashboard.empty-state icon="document-text" :title="$deliverables->total() ? 'No deliverables on this page.' : 'No deliverables yet.'" :description="$canUpload ? 'Submit the first file below to share work with your Client.' : 'Files submitted by the assigned Freelancer will appear here.'" />
        @else
            <ol class="space-y-4 p-5 sm:p-6" aria-label="Project deliverables">
                @foreach ($deliverables as $deliverable)
                    <li wire:key="deliverable-{{ $deliverable->id }}" class="tb-editor-preview rounded-xl border border-slate-200 p-5 dark:border-slate-700">
                        <h2 class="break-words text-lg font-semibold"><a class="tb-text-link" href="{{ route('projects.deliverables.show', [$project, $deliverable]) }}" wire:navigate>{{ $deliverable->title }}</a></h2>
                        <p class="tb-muted mt-2 break-words">{{ $deliverable->milestone ? 'Milestone: '.$deliverable->milestone->title : 'Not linked to a milestone' }}</p>
                        @if ($deliverable->latestVersion)
                            <div class="mt-4">
                                <x-deliverables.version :version="$deliverable->latestVersion" :project="$project" :deliverable="$deliverable" latest />
                            </div>
                        @endif
                        <flux:button :href="route('projects.deliverables.show', [$project, $deliverable])" class="mt-4" :aria-label="'View '.$deliverable->title.' and version history'" wire:navigate>View deliverable ({{ $deliverable->versions_count }} {{ Str::plural('version', $deliverable->versions_count) }})</flux:button>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-dashboard.panel>

    {{ $deliverables->links() }}

    @if ($canUpload)
        <x-deliverables.upload-form :milestones="$milestones" />
    @endif
    <flux:button :href="route('projects.plan', $project)" icon="calendar-days" wire:navigate>Project plan</flux:button>
</div>
