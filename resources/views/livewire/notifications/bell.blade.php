<?php

use App\Actions\Notifications\ManageNotifications;
use App\Livewire\Concerns\HandlesNotifications;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    use HandlesNotifications;

    #[On('notification-state-changed')]
    public function refreshAlerts(): void { ManageNotifications::account(); }

    public function with(): array
    {
        $user = ManageNotifications::account();
        return ['unreadCount' => $user->unreadNotifications()->count(), 'recent' => $user->notifications()->limit(5)->get()];
    }
}; ?>

<div wire:poll.20s.visible="refreshAlerts" class="shrink-0" x-data>
    <flux:dropdown position="bottom" align="end">
        <flux:button square variant="subtle" class="relative" :aria-label="'Notifications: '.$unreadCount.' unread'" title="Notifications">
            <flux:icon.bell class="size-5" aria-hidden="true" />
            @if ($unreadCount)<span class="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-teal-700 px-1 text-[10px] font-semibold leading-4 text-white dark:bg-teal-300 dark:text-slate-950" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
        </flux:button>
        <flux:menu class="w-[min(22rem,calc(100vw-2rem))]" aria-label="Recent notifications">
            <flux:menu.heading>Recent notifications · {{ $unreadCount }} unread</flux:menu.heading>
            @forelse ($recent as $notification)
                <flux:menu.item wire:click="openNotification('{{ $notification->id }}')" wire:loading.attr="disabled" wire:target="openNotification">
                    <span class="block min-w-0 whitespace-normal py-1"><span class="block break-words text-sm {{ $notification->unread() ? 'font-semibold' : 'font-normal' }}">{{ $notification->eventLabel() }}</span><span class="tb-muted mt-1 block text-xs">{{ $notification->created_at->setTimezone('Asia/Colombo')->format('j M Y, g:i a') }} · {{ $notification->unread() ? 'Unread' : 'Read' }}</span></span>
                </flux:menu.item>
            @empty
                <flux:menu.item disabled>No notifications yet.</flux:menu.item>
            @endforelse
            <flux:menu.separator />
            <flux:menu.item :href="route('notifications.index')" wire:navigate>View full inbox</flux:menu.item>
            @if ($unreadCount)<flux:menu.item wire:click="prepareMarkAll" wire:loading.attr="disabled" wire:target="prepareMarkAll">Mark all read...</flux:menu.item>@endif
        </flux:menu>
    </flux:dropdown>
    <p role="status" aria-live="polite" class="sr-only">{{ session('notification-saved') }}</p>
    <p role="status" aria-live="polite" class="sr-only" wire:loading wire:target="openNotification,prepareMarkAll">Updating notifications...</p>
    <flux:error name="notifications" />
    <x-notifications.mark-all-dialog :captured-count="$capturedCount" :dialog-id="$this->getId()" />
</div>
