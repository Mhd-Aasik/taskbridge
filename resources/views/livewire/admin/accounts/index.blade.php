<?php

use App\Models\User;
use App\Support\AccountManagement;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('Manage accounts')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public $search = '';
    #[Url(except: 'all')]
    public $role = 'all';
    #[Url(except: 'all')]
    public $status = 'all';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedRole(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function clearFilters(): void { AccountManagement::admin(); $this->reset('search', 'role', 'status'); $this->resetPage(); }

    public function with(): array
    {
        AccountManagement::admin();
        $validation = Validator::make(['search' => $this->search, 'role' => $this->role, 'status' => $this->status, 'page' => $this->paginators['page'] ?? request()->query('page', 1)], [
            'search' => ['nullable', 'string', 'max:100'], 'role' => ['required', 'string', 'in:all,client,freelancer,admin'],
            'status' => ['required', 'string', 'in:all,active,suspended'], 'page' => ['required', 'integer', 'between:1,10000'],
        ]);
        abort_if($validation->fails(), 422, 'Use a search of up to 100 characters, available role/status filters and a valid page.');
        $query = User::query()->select(AccountManagement::LIST_FIELDS)->withCount(['projects', 'submittedProposals', 'projectAssignments']);
        $search = Str::trim($this->search ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($match) => $match->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]));
        }
        if ($this->role !== 'all') { $query->where('role', $this->role); }
        if ($this->status !== 'all') { $this->status === 'active' ? $query->whereNull('suspended_at') : $query->whereNotNull('suspended_at'); }

        return ['accounts' => $query->orderBy('name')->orderBy('id')->paginate(10),
            'counts' => User::query()->selectRaw('COUNT(*) as total, SUM(CASE WHEN suspended_at IS NULL THEN 1 ELSE 0 END) as active')->first()];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-6xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="tb-eyebrow mb-2">Administration</p><h1 class="text-2xl font-bold sm:text-3xl">Manage accounts</h1><p class="tb-muted mt-2">{{ (int) $counts->total }} accounts · {{ (int) $counts->active }} active · {{ (int) $counts->total - (int) $counts->active }} suspended</p></div><flux:button :href="route('admin.dashboard')" class="self-start" wire:navigate>Admin dashboard</flux:button></div>
    <x-dashboard.panel title="Find an account" description="Administrative account details do not grant access to private work, photos or files.">
        <div class="space-y-4 p-5 sm:p-6"><div class="grid gap-4 md:grid-cols-3"><flux:input wire:model.live.debounce.350ms="search" label="Search name or email" maxlength="100" /><flux:select wire:model.live="role" label="Account role"><option value="all">All roles</option><option value="client">Client</option><option value="freelancer">Freelancer</option><option value="admin">Admin</option></flux:select><flux:select wire:model.live="status" label="Account status"><option value="all">All accounts</option><option value="active">Active</option><option value="suspended">Suspended</option></flux:select></div><flux:button type="button" wire:click="clearFilters" wire:loading.attr="disabled">Clear filters</flux:button></div>
    </x-dashboard.panel>
    <div class="space-y-4" aria-label="Account results">
        @forelse ($accounts as $account)
            <article wire:key="account-{{ $account->id }}" class="tb-panel space-y-4 p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h2 class="break-words text-lg font-semibold">{{ $account->name }}</h2><p class="tb-muted mt-1 break-all">{{ $account->email }}</p></div><span class="rounded-full border border-slate-300 px-3 py-1 text-sm font-medium dark:border-slate-600">{{ $account->isActive() ? 'Active' : 'Suspended' }}</span></div>
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-5"><div><dt class="tb-muted">Role</dt><dd class="mt-1 font-medium">{{ ucfirst($account->role) }}</dd></div><div><dt class="tb-muted">Registered in Sri Lanka</dt><dd class="mt-1 font-medium"><time datetime="{{ $account->created_at->toIso8601String() }}">{{ $account->created_at->timezone('Asia/Colombo')->format('j M Y') }}</time></dd></div><div><dt class="tb-muted">Owned projects</dt><dd class="mt-1 font-medium">{{ $account->projects_count }}</dd></div><div><dt class="tb-muted">Submitted proposals</dt><dd class="mt-1 font-medium">{{ $account->submitted_proposals_count }}</dd></div><div><dt class="tb-muted">Assignments</dt><dd class="mt-1 font-medium">{{ $account->project_assignments_count }}</dd></div></dl>
                <flux:button :href="route('admin.accounts.show', $account->id)" :aria-label="'Review account '.$account->name" wire:navigate>Review account</flux:button>
            </article>
        @empty
            <x-dashboard.panel title="No matching accounts"><x-dashboard.empty-state icon="users" title="No accounts match this page or filter." description="Clear the filters to return to the full account list." /></x-dashboard.panel>
        @endforelse
    </div>
    {{ $accounts->links() }}
    <p role="status" aria-live="polite" wire:loading>Loading accounts...</p>
</div>
