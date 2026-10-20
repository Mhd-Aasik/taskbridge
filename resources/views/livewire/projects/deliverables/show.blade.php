<?php

use App\Actions\Deliverables\SubmitDeliverable;
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

    public $submission_note = '';

    public $file = null;

    public function mount(Project $project, Deliverable $deliverable): void
    {
        $this->projectId = $project->id;
        $this->deliverableId = $deliverable->id;
        $this->submissionId = (string) Str::uuid();
        $this->authorisedContext();
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
        } catch (ValidationException|AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('deliverables', 'We could not submit this version. Your note has been kept and previous versions are unchanged. Please try again.');

            return;
        }

        session()->flash('deliverable-saved', 'New version submitted. Previous versions remain available. Submission does not mean Client approval or project acceptance.');
        $this->redirectRoute('projects.deliverables.show', ['project' => $this->projectId, 'deliverable' => $this->deliverableId], navigate: true);
    }

    public function with(): array
    {
        [$user, $project, $assignment, $deliverable] = $this->authorisedContext();
        $deliverable->load('milestone:id,title');

        return [
            'project' => $project,
            'deliverable' => $deliverable,
            'versions' => $deliverable->versions()->with('uploader:id,name')->orderByDesc('version_number')->orderByDesc('id')->get(),
            'canUpload' => Gate::forUser($user)->allows('uploadVersion', [$deliverable, $assignment, $project]),
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
    <flux:error name="deliverables" />

    @if ($deliverable->description !== null && $deliverable->description !== '')
        <x-dashboard.panel title="Deliverable description">
            <p class="whitespace-pre-wrap break-words p-5 leading-7 sm:p-6">{{ $deliverable->description }}</p>
        </x-dashboard.panel>
    @endif

    <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        <p>Each version is kept as submitted. Downloading or submitting a file does not mean Client approval or project acceptance.</p>
        @if (! $canUpload)
            <p class="mt-3">New versions can be uploaded only by the assigned Freelancer while the project is in progress.</p>
        @endif
    </div>

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
