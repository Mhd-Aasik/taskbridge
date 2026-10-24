<?php

use App\Actions\Projects\SaveProjectDraft;
use App\Livewire\Forms\ProjectDraftForm;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectCoverImages;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app'), Title('Project draft')] class extends Component {
    use WithFileUploads;

    public ProjectDraftForm $form;

    public $cover = null;

    public bool $removeCover = false;

    #[Locked]
    public bool $previewReady = false;

    #[Locked]
    public bool $hasActiveCategories = false;

    #[Locked]
    public ?int $projectId = null;

    public function mount(?Project $project = null): void
    {
        $this->projectId = $project?->getKey();
        [, $savedProject] = $this->authorisedContext();

        if ($savedProject) {
            $this->form->loadProject($savedProject);
        }
    }

    public function updatedCover(): void
    {
        $this->authorisedContext();
        $this->previewReady = false;
        $this->removeCover = false;
        $this->resetValidation('cover');
        if ($this->cover !== null) {
            app(ProjectCoverImages::class)->prepare($this->cover);
            $this->previewReady = true;
        }
    }

    public function clearCover(): void
    {
        $this->authorisedContext();
        $this->reset('cover', 'previewReady');
        $this->resetValidation('cover');
        $this->dispatch('project-cover-reset');
    }

    public function updatedRemoveCover(): void
    {
        $this->authorisedContext();
        if ($this->removeCover) {
            $this->clearCover();
        }
    }

    public function save(SaveProjectDraft $drafts): void
    {
        $this->authorisedContext();
        $details = $this->form->validatedDetails();
        $this->resetValidation('cover');
        try {
            $project = $drafts->save($this->projectId, $details, $this->cover, $this->removeCover);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[array_key_exists($field, $this->form->all()) ? 'form.'.$field : $field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('cover', 'We could not save your draft. Your saved details and cover have been kept. Please try again.');

            return;
        }

        session()->flash('project-saved', $this->projectId ? 'Project changes saved.' : 'Project draft saved.');
        $this->projectId = $project->id;
        if ($this->cover instanceof TemporaryUploadedFile) {
            try {
                $this->cover->delete();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $this->reset('cover', 'previewReady', 'removeCover');
        $this->redirectRoute('client.projects.edit', ['project' => $project->id], navigate: true);
    }

    protected function authorisedContext(bool $lock = false): array
    {
        $users = User::query()->whereKey(Auth::id());
        if ($lock) {
            $users->lockForUpdate();
        }
        $user = $users->first();
        abort_unless($user, 403);

        $project = null;
        if ($this->projectId !== null) {
            $projects = Project::query()->whereKey($this->projectId);
            if ($lock) {
                $projects->lockForUpdate();
            }
            $project = $projects->firstOrFail();
        }

        Gate::forUser($user)->authorize($project ? 'update' : 'create', $project ?? Project::class);

        return [$user, $project];
    }

    public function with(): array
    {
        [, $project] = $this->authorisedContext();
        $category = $project?->category;
        $categories = Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $this->hasActiveCategories = $categories->isNotEmpty();

        return [
            'categories' => $categories,
            'inactiveCategory' => $category && ! $category->is_active ? $category : null,
            'earliestDeadline' => ProjectDraftForm::earliestDeadline(),
            'savedProject' => $project,
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="tb-eyebrow mb-2">Client workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $projectId ? 'Edit project draft' : 'Create project' }}</h1>
            <p class="tb-muted mt-2 max-w-xl">Put your next collaboration into words. You can return to your draft anytime.</p>
        </div>
        <flux:button :href="route('client.projects.index')" icon="arrow-left" class="self-start" wire:navigate>All projects</flux:button>
    </div>

    <div class="tb-editor-notice flex items-start gap-3 rounded-xl border border-teal-200 p-4 dark:border-teal-900">
        <flux:icon.lock-closed class="mt-1 size-5 shrink-0" />
        <p><strong>Drafts are private.</strong> They are not available to freelancers. Review your saved draft before publishing.</p>
    </div>

    @if ($inactiveCategory)
        <div role="note" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-slate-900 dark:border-amber-700 dark:bg-slate-800 dark:text-slate-100">
            <p><strong>Saved category: {{ $inactiveCategory->name }} (inactive).</strong> Select an active category before saving this draft.</p>
        </div>
    @endif

    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('project-saved') }}</p>

    <form wire:submit="save" class="space-y-6"
        x-data="{ preview: null, uploading: false, progress: 0, uploadError: '' }"
        x-on:project-cover-reset.window="if (preview) URL.revokeObjectURL(preview); preview = null; $refs.cover.value = ''"
        x-on:livewire-upload-start="uploading = true; progress = 0; uploadError = ''"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="uploading = false; uploadError = 'The upload failed. Please choose the image again.'"
        x-on:livewire-upload-cancel="uploading = false"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
    >
        <fieldset wire:loading.attr="disabled" wire:target="save,cover" class="min-w-0 space-y-6">
            <legend class="sr-only">Project draft details</legend>
            <x-dashboard.panel title="Project cover (optional)" description="Add an image that supports your brief, or keep the simple category illustration.">
                <div class="space-y-4 p-5 sm:p-6">
                    @if ($savedProject?->coverUrl())
                        <div class="max-w-md" x-show="! $wire.removeCover">
                            <x-projects.cover :project="$savedProject" />
                            <p class="tb-muted mt-2 text-sm">Saved cover</p>
                        </div>
                    @else
                        <p class="tb-muted text-sm">No cover saved. Your project works just as well without an image.</p>
                    @endif
                    <template x-if="preview && $wire.previewReady && !uploading">
                        <div class="max-w-md space-y-2">
                            <img x-bind:src="preview" alt="Selected project cover preview" width="1600" height="700" class="aspect-[16/7] w-full rounded-xl border border-slate-300 object-cover dark:border-slate-600" />
                            <p class="tb-muted text-sm">Private preview. This image is applied when you save the draft.</p>
                        </div>
                    </template>
                    <flux:field>
                        <flux:label for="project-cover-upload">Choose a cover image</flux:label>
                        <input id="project-cover-upload" x-ref="cover" wire:model="cover" type="file" accept="image/jpeg,image/png,image/webp"
                            aria-describedby="project-cover-help project-cover-error" @error('cover') aria-invalid="true" @enderror
                            data-flux-control class="block w-full rounded-lg border border-slate-400 bg-slate-50 p-3 text-base text-slate-900 file:me-3 file:rounded-md file:border-0 file:bg-teal-700 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:border-slate-500 dark:bg-[#142033] dark:text-slate-100 dark:file:bg-teal-300 dark:file:text-teal-950"
                            x-on:change="if (preview) URL.revokeObjectURL(preview); preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null" />
                        <flux:description id="project-cover-help">JPEG, PNG, or WebP. Up to 2 MiB and 4096 × 4096 pixels. Images are resized and metadata is removed.</flux:description>
                        <flux:error name="cover" id="project-cover-error" />
                    </flux:field>
                    @if ($cover !== null)
                        <flux:button type="button" wire:click="clearCover" wire:loading.attr="disabled" wire:target="cover,save,clearCover">Discard selected image</flux:button>
                    @endif
                    @if ($savedProject?->coverUrl())
                        <flux:checkbox wire:model.live="removeCover" label="Remove the saved cover when saving this draft" />
                    @endif
                    @if ($removeCover)
                        <p role="status" class="tb-muted text-sm">The saved cover will be removed when you save. Other project details will be kept.</p>
                    @endif
                    <p x-show="uploadError" x-cloak role="alert" class="text-sm font-medium text-red-700 dark:text-red-300" x-text="uploadError"></p>
                    <p x-show="uploading" x-cloak role="status" class="tb-muted text-sm" x-text="'Uploading cover… ' + progress + '%'"></p>
                    <p wire:loading wire:target="cover" role="status" class="tb-muted text-sm">Checking image…</p>
                </div>
            </x-dashboard.panel>
            <x-dashboard.panel title="Your project brief" description="A clear outcome, a realistic budget, and enough time to do good work.">
                <div class="space-y-6 p-5 sm:p-6">
                    <flux:input wire:model="form.title" label="Project title" description="Up to 150 characters." maxlength="150" required />
                    <flux:textarea wire:model.blur="form.description" label="Project description" description="Goals and deliverables. Plain text, up to 10,000 characters." rows="9" maxlength="10000" required />
                    @if ($form->description !== '')
                        <div class="tb-editor-preview rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                            <h2 class="tb-eyebrow mb-2">Description preview</h2>
                            <p class="whitespace-pre-wrap break-words">{{ $form->description }}</p>
                        </div>
                    @endif
                    <flux:select wire:model="form.category_id" label="Project category" description="Choose an active category." required>
                        <option value="">Choose a category</option>
                        @if ($inactiveCategory)
                            <option value="{{ $inactiveCategory->id }}" disabled>{{ $inactiveCategory->name }} (inactive)</option>
                        @endif
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </flux:select>
                    @if ($categories->isEmpty())
                        <p class="tb-muted">No active categories are available. Please try again later.</p>
                    @endif
                    <div class="grid items-start gap-6 sm:grid-cols-2">
                        <flux:input wire:model="form.budget" label="Fixed budget (LKR)" inputmode="decimal" placeholder="e.g. 25000.00" description:trailing="LKR 1–9,999,999,999.99. Up to 2 decimals." required />
                        <flux:input wire:model="form.deadline" label="Project deadline" type="date" :min="$earliestDeadline" description:trailing="Tomorrow or later (Sri Lanka)." required />
                    </div>
                </div>
            </x-dashboard.panel>
        </fieldset>
        <div wire:key="project-actions-{{ (int) $hasActiveCategories }}" class="flex flex-wrap items-center gap-4">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,cover" x-bind:disabled="uploading || ! $wire.hasActiveCategories" :disabled="! $hasActiveCategories">
                <span wire:loading.remove wire:target="save">{{ $projectId ? 'Save changes' : 'Save draft' }}</span>
                <span wire:loading wire:target="save" role="status">Saving draft...</span>
            </flux:button>
            <flux:button :href="route('client.projects.index')" variant="ghost" wire:navigate>Back to projects</flux:button>
            @if ($projectId)
                <flux:button :href="route('client.projects.show', $projectId)" wire:navigate>Review project</flux:button>
            @endif
        </div>
    </form>
</div>
