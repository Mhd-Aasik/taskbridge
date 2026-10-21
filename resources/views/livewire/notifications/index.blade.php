<?php

use App\Actions\Notifications\ManageNotifications;
use App\Livewire\Concerns\HandlesNotifications;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('Notifications')] class extends Component {
    use HandlesNotifications, WithPagination;

    #[Url(except: 'all')]
    public $filter = 'all';

    public function updatedFilter(): void { $this->resetPage(); }

    #[On('notification-state-changed')]
    public function refreshAlerts(): void { ManageNotifications::account(); }

    public function with(): array
    {
        $user = ManageNotifications::account();
        $validator = Validator::make(['filter' => $this->filter, 'page' => $this->paginators['page'] ?? request()->query('page', 1)], ['filter' => ['required', 'string', 'in:all,unread'], 'page' => ['required', 'integer', 'min:1', 'max:10000']]);
        abort_if($validator->fails(), 422, 'Choose All or Unread and a notification page between 1 and 10,000.');
        return ['unreadCount' => $user->unreadNotifications()->count(),
            'alerts' => $user->notifications()->when($this->filter === 'unread', fn ($query) => $query->unread())->paginate(20)];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6" wire:poll.20s.visible="refreshAlerts" x-data>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="tb-eyebrow mb-2">Your account</p><h1 class="text-2xl font-bold sm:text-3xl">Notifications</h1><p class="tb-muted mt-2">{{ $unreadCount }} unread {{ $unreadCount === 1 ? 'notification' : 'notifications' }}. Alerts and conversation read state are separate.</p></div>@if ($unreadCount)<flux:button type="button" wire:click="prepareMarkAll" wire:loading.attr="disabled" wire:target="prepareMarkAll" class="self-start">Mark all read</flux:button>@endif</div>
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('notification-saved') }}</p>
    @if (session('notification-unavailable'))<p role="status" class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">{{ session('notification-unavailable') }}</p>@endif
    <flux:error name="notifications" />
    <flux:select wire:model.live="filter" label="Show notifications" class="max-w-xs"><option value="all">All notifications</option><option value="unread">Unread notifications</option></flux:select>
    <x-dashboard.panel title="Your private inbox">
        @if ($alerts->isEmpty())
            <x-dashboard.empty-state icon="bell" :title="$filter === 'unread' ? 'You have no unread notifications.' : 'No notifications yet.'" description="Updates from your authorised project activity will appear here." />
        @else
            <ol class="divide-y divide-slate-200 dark:divide-slate-700" aria-label="Notifications">
                @foreach ($alerts as $notification)
                    <li wire:key="notification-{{ $notification->id }}" class="space-y-3 p-5 sm:p-6">
                        <p class="break-words font-semibold">{{ $notification->eventLabel() }}</p>
                        <p class="tb-muted text-sm"><time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo) · {{ $notification->unread() ? 'Unread' : 'Read' }}</p>
                        <div class="flex flex-wrap gap-3"><flux:button type="button" wire:click="openNotification('{{ $notification->id }}')" wire:loading.attr="disabled" wire:target="openNotification">Open notification</flux:button><flux:button type="button" wire:click="setNotificationRead('{{ $notification->id }}', {{ $notification->unread() ? 'true' : 'false' }})" wire:loading.attr="disabled" wire:target="setNotificationRead">{{ $notification->unread() ? 'Mark read' : 'Mark unread' }}</flux:button></div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-dashboard.panel>
    <p role="status" aria-live="polite" wire:loading wire:target="setNotificationRead,openNotification,prepareMarkAll">Updating notifications...</p>
    {{ $alerts->links() }}
    <x-notifications.mark-all-dialog :captured-count="$capturedCount" :dialog-id="$this->getId()" />
</div>
