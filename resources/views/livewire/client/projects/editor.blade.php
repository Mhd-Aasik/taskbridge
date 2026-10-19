<?php

use App\Livewire\Forms\ProjectDraftForm;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Project draft')] class extends Component {
    public ProjectDraftForm $form;

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

    public function save(): void
    {
        $id = DB::transaction(function (): int {
            // Re-read and lock the persisted account and draft, never a submitted model.
            [$user, $project] = $this->authorisedContext(lock: true);
            $details = $this->form->validatedDetails();
            $category = Category::query()->whereKey($details['category_id'])->where('is_active', true)->lockForUpdate()->first();
            if (! $category) {
                throw ValidationException::withMessages(['form.category_id' => 'Choose an active project category.']);
            }

            if ($project) {
                $project->fill($details)->saveOrFail();
            } else {
                $project = $user->projects()->create($details);
            }

            return $project->id;
        });

        session()->flash('project-saved', $this->projectId ? 'Project changes saved.' : 'Project draft saved.');
        $this->projectId = $id;
        $this->redirectRoute('client.projects.edit', ['project' => $id], navigate: true);
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

        return [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'inactiveCategory' => $category && ! $category->is_active ? $category : null,
            'earliestDeadline' => ProjectDraftForm::earliestDeadline(),
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
        <p><strong>Drafts are private.</strong> They are not available to freelancers. Publishing is not available yet.</p>
    </div>

    @if ($inactiveCategory)
        <div role="note" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-slate-900 dark:border-amber-700 dark:bg-slate-800 dark:text-slate-100">
            <p><strong>Saved category: {{ $inactiveCategory->name }} (inactive).</strong> Select an active category before saving this draft.</p>
        </div>
    @endif

    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('project-saved') }}</p>

    <form wire:submit="save" class="space-y-6">
        <fieldset wire:loading.attr="disabled" wire:target="save" class="min-w-0">
            <legend class="sr-only">Project draft details</legend>
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
        <div class="flex flex-wrap items-center gap-4">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save" :disabled="$categories->isEmpty()">
                <span wire:loading.remove wire:target="save">{{ $projectId ? 'Save changes' : 'Save draft' }}</span>
                <span wire:loading wire:target="save" role="status">Saving draft...</span>
            </flux:button>
            <flux:button :href="route('client.projects.index')" variant="ghost" wire:navigate>Back to projects</flux:button>
        </div>
    </form>
</div>
