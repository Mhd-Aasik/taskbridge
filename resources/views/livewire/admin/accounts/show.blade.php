<?php

use App\Actions\Accounts\ChangeAccountStatus;
use App\Models\User;
use App\Support\AccountManagement;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('Account review')] class extends Component {
    use WithPagination;

    #[Locked]
    public int $accountId;
    #[Locked]
    public bool $suspend = false;
    #[Locked]
    public string $revision = '';
    #[Locked]
    public string $actionId = '';
    #[Locked]
    public string $targetName = '';
    #[Locked]
    public string $targetEmail = '';
    public string $reason = '';
    public bool $confirming = false;

    public function mount(int $account): void
    {
        $this->accountId = $account;
        $this->account();
    }

    protected function account(): User
    {
        $actor = AccountManagement::admin();
        $account = User::query()->select(AccountManagement::STATUS_FIELDS)->findOrFail($this->accountId);
        Gate::forUser($actor)->authorize('viewAdmin', $account);
        return $account;
    }

    public function prepareStatus(): void
    {
        $account = $this->account();
        Gate::forUser(AccountManagement::admin())->authorize('changeStatus', $account);
        $this->suspend = $account->isActive();
        $this->revision = AccountManagement::version($account);
        $this->actionId = (string) Str::uuid();
        $this->targetName = $account->name;
        $this->targetEmail = $account->email;
        $this->reason = '';
        $this->resetValidation();
        $this->confirming = true;
    }

    public function confirmStatus(): void
    {
        abort_unless($this->revision !== '' && $this->actionId !== '', 422);
        $this->resetValidation();
        $result = app(ChangeAccountStatus::class)->change($this->accountId, $this->suspend, ['reason' => $this->reason], $this->revision, $this->actionId, true);
        $this->confirming = false;
        $this->reason = '';
        session()->flash('account-status', $result['replayed'] ? 'This request was already completed. Later account changes were preserved.' : ($result['changed'] ? ($this->suspend ? 'Account suspended. Existing work and history were preserved.' : 'Account reactivated. A fresh login is required; existing work and publication times were preserved.') : 'The account already has this status.'));
    }

    public function with(): array
    {
        $account = $this->account();
        $page = $this->paginators['page'] ?? request()->query('page', 1);
        abort_if(\Illuminate\Support\Facades\Validator::make(['page' => $page], ['page' => ['required', 'integer', 'between:1,10000']])->fails(), 422);
        $account->loadCount(['projects', 'submittedProposals', 'projectAssignments', 'projectMessages', 'createdMilestones', 'createdDeliverables']);
        $history = AccountManagement::history($account)->select(['id', 'actor_id', 'subject_type', 'subject_id', 'action', 'before_values', 'after_values', 'occurred_at'])->with('actor:id,name')->orderByDesc('id')->paginate(10);
        return ['account' => $account, 'history' => $history, 'canChangeStatus' => Gate::forUser(AccountManagement::admin())->allows('changeStatus', $account)];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-5xl space-y-6" x-data>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><p class="tb-eyebrow mb-2">Administration</p><h1 class="break-words text-2xl font-bold sm:text-3xl">{{ $account->name }}</h1><p class="tb-muted mt-2 break-all">{{ $account->email }}</p></div><flux:button :href="route('admin.accounts.index')" class="self-start" wire:navigate>All accounts</flux:button></div>
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('account-status') }}</p>
    <flux:error name="account" />
    <x-dashboard.panel title="Account details">
        <div class="space-y-5 p-5 sm:p-6"><dl class="grid gap-5 sm:grid-cols-3"><div><dt class="tb-muted text-sm">Role</dt><dd class="mt-1 font-medium">{{ ucfirst($account->role) }}</dd></div><div><dt class="tb-muted text-sm">Status</dt><dd class="mt-1 font-semibold">{{ $account->isActive() ? 'Active' : 'Suspended' }}</dd></div><div><dt class="tb-muted text-sm">Registered in Sri Lanka</dt><dd class="mt-1"><time datetime="{{ $account->created_at->toIso8601String() }}">{{ $account->created_at->timezone('Asia/Colombo')->format('j M Y, g:i a') }}</time></dd></div></dl>
            @if (!$account->isActive())<div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900"><p class="font-medium">Suspended {{ $account->suspended_at->timezone('Asia/Colombo')->format('j M Y, g:i a') }} (Asia/Colombo)</p><p class="tb-muted mt-2 text-sm">Internal administrative reason — not shown to the affected account owner.</p><p class="mt-2 whitespace-pre-wrap break-words leading-7">{{ $account->suspension_reason }}</p></div>@endif
            @if ($canChangeStatus)<flux:button type="button" variant="primary" wire:click="prepareStatus" wire:loading.attr="disabled" wire:target="prepareStatus">{{ $account->isActive() ? 'Suspend account' : 'Reactivate account' }}</flux:button>@else<p class="tb-muted leading-7">Administrator accounts are protected. Their status cannot be changed here.</p>@endif
        </div>
    </x-dashboard.panel>
    <x-dashboard.panel title="Associated records" description="Counts preserve context without exposing private project, profile, message or file contents."><dl class="grid grid-cols-2 gap-5 p-5 text-sm sm:grid-cols-3 sm:p-6">@foreach (['projects_count' => 'Owned projects', 'submitted_proposals_count' => 'Submitted proposals', 'project_assignments_count' => 'Assignments', 'project_messages_count' => 'Authored messages', 'created_milestones_count' => 'Created milestones', 'created_deliverables_count' => 'Created deliverables'] as $field => $label)<div><dt class="tb-muted">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold">{{ $account->{$field} }}</dd></div>@endforeach</dl></x-dashboard.panel>
    <x-dashboard.panel title="Administrative status history" description="Internal, permanent history. Reasons are visible only to authorized administrators.">
        <div class="space-y-4 p-5 sm:p-6">@forelse ($history as $entry)<article wire:key="account-audit-{{ $entry->id }}" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700"><h2 class="font-semibold">{{ $entry->action->value === 'account.suspended' ? 'Account suspended' : 'Account reactivated' }}</h2><p class="tb-muted mt-1 text-sm">{{ $entry->actor->name }} · <time datetime="{{ $entry->occurred_at->toIso8601String() }}">{{ $entry->occurred_at->timezone('Asia/Colombo')->format('j M Y, g:i a') }}</time> (Asia/Colombo)</p><p class="mt-3 whitespace-pre-wrap break-words leading-7">{{ $entry->after_values['reason'] }}</p><p class="tb-muted mt-2 text-sm">{{ ucfirst($entry->before_values['status']) }} → {{ ucfirst($entry->after_values['status']) }}</p></article>@empty<p class="tb-muted leading-7">No administrative status changes have been recorded for this account.</p>@endforelse{{ $history->links() }}</div>
    </x-dashboard.panel>
    <p role="status" aria-live="polite" wire:loading wire:target="prepareStatus">Preparing account confirmation...</p>
    <dialog x-ref="statusDialog" x-effect="if ($wire.confirming && !$refs.statusDialog.open) $refs.statusDialog.showModal(); else if (!$wire.confirming && $refs.statusDialog.open) $refs.statusDialog.close()" x-on:cancel="$wire.confirming = false" aria-labelledby="account-status-heading" aria-describedby="account-status-explanation" class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
        <h2 id="account-status-heading" class="text-lg font-semibold">{{ $suspend ? 'Suspend' : 'Reactivate' }} this account?</h2>
        <div id="account-status-explanation" class="mt-4 space-y-3 leading-7"><p class="break-words font-medium">{{ $targetName }}</p><p class="tb-muted break-all">{{ $targetEmail }}</p><p class="tb-muted">{{ $suspend ? 'This ends account access and revokes existing sessions and remember-me authentication. Client projects leave discovery; a suspended Freelancer cannot be selected for a new assignment. Existing work, files and history are preserved, and active counterparties keep their authorized access.' : 'This restores access through the normal business rules. The owner must log in again. It does not restore old sessions, republish projects or change agreed terms.' }}</p></div>
        <form wire:submit="confirmStatus" class="mt-5 space-y-5"><flux:error name="account" /><flux:textarea wire:model="reason" label="Internal administrative reason" description="Required, up to 1,000 characters. Not shown to the affected account owner." maxlength="1000" rows="4" required /><div class="flex flex-wrap justify-end gap-3"><flux:button type="button" x-on:click="$wire.confirming = false" wire:loading.attr="disabled" wire:target="confirmStatus" autofocus>Cancel</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="confirmStatus">Confirm {{ $suspend ? 'suspension' : 'reactivation' }}</flux:button></div><p role="status" aria-live="polite" wire:loading wire:target="confirmStatus">Updating account status...</p></form>
    </dialog>
</div>
