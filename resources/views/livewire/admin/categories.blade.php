<?php

use App\Actions\Categories\ManageCategory;
use App\Models\Category;
use App\Support\CategoryManagement;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('Manage categories')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public $search = '';
    #[Url(except: 'all')]
    public $status = 'all';
    #[Locked]
    public ?int $editingId = null;
    #[Locked]
    public string $editVersion = '';
    #[Locked]
    public string $saveActionId;
    #[Locked]
    public string $savedSlug = '';
    public $name = '';
    public $description = '';

    #[Locked]
    public ?int $activationId = null;
    #[Locked]
    public bool $activationTarget = false;
    #[Locked]
    public string $activationVersion = '';
    #[Locked]
    public array $activationImpact = [];
    #[Locked]
    public string $activationName = '';
    #[Locked]
    public string $activationActionId = '';
    public bool $confirmingActivation = false;

    public function mount(): void { $this->newCategory(); }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function clearFilters(): void { CategoryManagement::admin(); $this->reset('search', 'status'); $this->resetPage(); }

    public function newCategory(): void
    {
        CategoryManagement::admin();
        $this->reset('editingId', 'editVersion', 'savedSlug', 'name', 'description');
        $this->saveActionId = (string) Str::uuid();
        $this->resetValidation();
    }

    public function editCategory(int $id): void
    {
        $actor = CategoryManagement::admin();
        $category = Category::query()->findOrFail($id);
        Gate::forUser($actor)->authorize('update', $category);
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->description = $category->description ?? '';
        $this->savedSlug = $category->slug;
        $this->editVersion = CategoryManagement::version($category);
        $this->saveActionId = (string) Str::uuid();
        $this->resetValidation();
        $this->dispatch('category-editor-focus');
    }

    public function save(): void
    {
        $this->resetValidation();
        $actions = app(ManageCategory::class);
        $input = ['name' => $this->name, 'description' => $this->description];
        $result = $this->editingId === null ? $actions->create($input, $this->saveActionId) : $actions->edit($this->editingId, $input, $this->editVersion, $this->saveActionId);
        $this->editCategory($result['category']->id);
        session()->flash('category-saved', $result['replayed'] ? 'This action was already completed. The latest category details are shown.' : ($result['changed'] ? 'Category saved. Its slug and project references are preserved.' : 'No descriptive changes were needed.'));
    }

    public function prepareActivation(int $id, bool $active): void
    {
        $actor = CategoryManagement::admin();
        $category = Category::query()->findOrFail($id);
        Gate::forUser($actor)->authorize('setActive', $category);
        $this->activationId = $id;
        $this->activationName = $category->name;
        $this->activationTarget = $active;
        $this->activationVersion = CategoryManagement::version($category, true);
        $this->activationImpact = CategoryManagement::impact($category);
        $this->activationActionId = (string) Str::uuid();
        $this->confirmingActivation = true;
        $this->resetValidation();
    }

    public function confirmActivation(): void
    {
        abort_unless($this->activationId !== null, 422);
        $this->resetValidation();
        $result = app(ManageCategory::class)->setActive($this->activationId, $this->activationTarget, $this->activationVersion, $this->activationImpact, $this->activationActionId, true);
        $this->confirmingActivation = false;
        session()->flash('category-saved', $result['replayed'] ? 'This activation action was already completed; later changes were preserved.' : ($result['changed'] ? 'Category '.($this->activationTarget ? 'activated' : 'deactivated').'. Project records and publication times are unchanged.' : 'The category already has this activation state.'));
    }

    public function with(): array
    {
        CategoryManagement::admin();
        $validator = Validator::make(['search' => $this->search, 'status' => $this->status, 'page' => $this->paginators['page'] ?? request()->query('page', 1)], [
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['required', 'string', 'in:all,active,inactive'], 'page' => ['required', 'integer', 'min:1', 'max:10000']]);
        abort_if($validator->fails(), 422, 'Use a search of up to 100 characters, an available activation filter and a valid page.');
        $query = CategoryManagement::withImpact(Category::query());
        $search = Str::trim($this->search ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($match) => $match->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]));
        }
        if ($this->status !== 'all') { $query->where('is_active', $this->status === 'active'); }

        return ['categories' => $query->orderBy('name')->orderBy('id')->paginate(10),
            'totals' => Category::query()->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')->first()];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-6xl space-y-6" x-data x-on:category-editor-focus.window="$nextTick(() => $refs.categoryName.focus())">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="tb-eyebrow mb-2">Administration</p><h1 class="text-2xl font-bold sm:text-3xl">Manage categories</h1><p class="tb-muted mt-2">{{ (int) $totals->total }} categories · {{ (int) $totals->active }} active · {{ (int) $totals->total - (int) $totals->active }} inactive</p></div><flux:button :href="route('admin.dashboard')" class="self-start" wire:navigate>Admin dashboard</flux:button></div>
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('category-saved') }}</p>
    <flux:error name="category" />
    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,23rem)]">
        <div class="min-w-0 space-y-5">
            <x-dashboard.panel title="Category catalogue" description="Counts show associations only; private project details remain protected.">
                <div class="space-y-4 p-5 sm:p-6"><div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model.live.debounce.350ms="search" label="Search categories" placeholder="Name or description" maxlength="100" /><flux:select wire:model.live="status" label="Activation filter"><option value="all">All categories</option><option value="active">Active only</option><option value="inactive">Inactive only</option></flux:select></div><flux:button type="button" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters">Clear filters</flux:button></div>
            </x-dashboard.panel>
            @forelse ($categories as $category)
                <article wire:key="category-{{ $category->id }}" class="tb-panel space-y-4 p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3"><h2 class="min-w-0 break-words text-lg font-semibold">{{ $category->name }}</h2><span class="rounded-full border border-slate-300 px-3 py-1 text-sm font-medium dark:border-slate-600">{{ $category->is_active ? 'Active' : 'Inactive' }}</span></div>
                    <p class="tb-muted break-all text-sm">Stable slug: {{ $category->slug }}</p>
                    <p class="whitespace-pre-wrap break-words leading-7">{{ $category->description ?? 'No description provided.' }}</p>
                    <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4"><div><dt class="tb-muted">Associated projects</dt><dd class="mt-1 text-base font-semibold">{{ $category->projects_count }}</dd></div><div><dt class="tb-muted">Open projects</dt><dd class="mt-1 text-base font-semibold">{{ $category->open_projects_count }}</dd></div><div><dt class="tb-muted">In discovery now</dt><dd class="mt-1 text-base font-semibold">{{ $category->discoverable_projects_count }}</dd></div><div><dt class="tb-muted">Assigned projects</dt><dd class="mt-1 text-base font-semibold">{{ $category->assigned_projects_count }}</dd></div></dl>
                    <div class="flex flex-wrap gap-3"><flux:button type="button" wire:click="editCategory({{ $category->id }})" wire:loading.attr="disabled" wire:target="editCategory" :aria-label="'Edit category '.$category->name">Edit details</flux:button><flux:button type="button" wire:click="prepareActivation({{ $category->id }}, {{ $category->is_active ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:target="prepareActivation">{{ $category->is_active ? 'Deactivate' : 'Activate' }}</flux:button></div>
                </article>
            @empty
                <x-dashboard.panel title="No categories on this page"><x-dashboard.empty-state icon="tag" :title="(int) $totals->total ? 'No categories match this page or filter.' : 'No categories yet.'" description="Create a category using the form, or clear filters and return to the first page." /></x-dashboard.panel>
            @endforelse
            {{ $categories->links() }}
        </div>
        <x-dashboard.panel :title="$editingId ? 'Edit category details' : 'Create category'">
            <form wire:submit="save" class="space-y-5 p-5 sm:p-6">
                <flux:error name="category" />
                <flux:input wire:model="name" label="Category name" maxlength="100" required x-ref="categoryName" />
                <flux:textarea wire:model="description" label="Description (optional)" description="Plain text, up to 1,000 characters." maxlength="1000" rows="5" />
                @if ($editingId)<p class="tb-muted break-all text-sm">Stable slug: {{ $savedSlug }}. Editing these details does not change activation.</p>@else<p class="tb-muted text-sm leading-6">New categories start active. A unique normalized slug is generated from the name and then remains permanent.</p>@endif
                <div class="flex flex-wrap gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save details' : 'Create category' }}</flux:button>@if ($editingId)<flux:button type="button" wire:click="newCategory" wire:loading.attr="disabled" wire:target="save,newCategory">New category / cancel edit</flux:button>@endif</div>
                <p role="status" aria-live="polite" wire:loading wire:target="save">Saving category...</p>
            </form>
        </x-dashboard.panel>
    </div>
    <p role="status" aria-live="polite" wire:loading wire:target="editCategory,prepareActivation,clearFilters">Loading category details...</p>
    <dialog x-ref="activationDialog" x-effect="if ($wire.confirmingActivation && !$refs.activationDialog.open) $refs.activationDialog.showModal(); else if (!$wire.confirmingActivation && $refs.activationDialog.open) $refs.activationDialog.close()" x-on:cancel="$wire.confirmingActivation = false" aria-labelledby="category-activation-heading" aria-describedby="category-activation-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
        <h2 id="category-activation-heading" class="break-words text-lg font-semibold">{{ $activationTarget ? 'Activate' : 'Deactivate' }} {{ $activationName }}?</h2>
        <div id="category-activation-explanation" class="tb-muted mt-4 space-y-3 leading-7">
            <p>{{ $activationImpact['total'] ?? 0 }} associated projects; {{ $activationImpact['open'] ?? 0 }} open; {{ $activationImpact['discoverable'] ?? 0 }} currently in discovery; {{ $activationImpact['assigned'] ?? 0 }} assigned.</p>
            @if ($activationTarget)<p>Reactivation restores eligibility through the normal publication, status and date rules. It does not republish projects or reset publication timestamps.</p>@else<p>New projects and publication cannot use an inactive category. Associated open projects leave Freelancer discovery. Existing assigned work and history remain available.</p>@endif
            <p>Project statuses, agreed terms, ownership and category references will not be changed.</p>
        </div>
        <flux:error name="category" />
        <div class="mt-5 flex flex-wrap justify-end gap-3"><flux:button type="button" x-on:click="$wire.confirmingActivation = false" wire:loading.attr="disabled" wire:target="confirmActivation" autofocus>Cancel</flux:button>@if ($activationId !== null)<flux:button type="button" wire:click="prepareActivation({{ $activationId }}, {{ $activationTarget ? 'true' : 'false' }})" wire:loading.attr="disabled" wire:target="confirmActivation,prepareActivation">Refresh counts</flux:button>@endif<flux:button type="button" variant="primary" wire:click="confirmActivation" wire:loading.attr="disabled" wire:target="confirmActivation">Confirm {{ $activationTarget ? 'activation' : 'deactivation' }}</flux:button></div>
        <p role="status" aria-live="polite" wire:loading wire:target="confirmActivation">Updating category activation...</p>
    </dialog>
</div>
