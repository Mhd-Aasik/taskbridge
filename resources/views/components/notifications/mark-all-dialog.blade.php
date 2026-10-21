@props(['capturedCount', 'dialogId'])

<dialog x-ref="markAllDialog" aria-labelledby="notification-read-heading-{{ $dialogId }}" aria-describedby="notification-read-explanation-{{ $dialogId }}"
    x-effect="if ($wire.confirmingAll &amp;&amp; !$refs.markAllDialog.open) $refs.markAllDialog.showModal(); else if (!$wire.confirmingAll &amp;&amp; $refs.markAllDialog.open) $refs.markAllDialog.close()"
    x-on:cancel="$wire.confirmingAll = false"
    class="tb-panel m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto! p-6 text-slate-900 shadow-xl dark:text-slate-100">
    <h2 id="notification-read-heading-{{ $dialogId }}" class="text-lg font-semibold">Mark captured notifications read?</h2>
    <p id="notification-read-explanation-{{ $dialogId }}" class="tb-muted mt-3 leading-7">This selection contains {{ $capturedCount }} existing unread {{ $capturedCount === 1 ? 'notification' : 'notifications' }}. Notifications arriving after this selection remain unread. Conversation messages keep their separate read state.</p>
    <flux:error name="notifications" />
    <div class="mt-5 flex flex-wrap justify-end gap-3">
        <flux:button type="button" x-on:click="$wire.confirmingAll = false" wire:loading.attr="disabled" wire:target="confirmMarkAll" autofocus>Keep unread</flux:button>
        <flux:button type="button" wire:click="confirmMarkAll" variant="primary" wire:loading.attr="disabled" wire:target="confirmMarkAll">Confirm mark all read</flux:button>
    </div>
    <p role="status" aria-live="polite" wire:loading wire:target="confirmMarkAll">Updating captured notifications...</p>
</dialog>
