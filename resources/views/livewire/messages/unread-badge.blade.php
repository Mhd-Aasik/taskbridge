<?php

use App\Support\ProjectMessaging;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public ?int $projectId = null;

    #[On('project-message-read'), On('project-message-sent')]
    public function refreshUnread(): void { $this->with(); }

    public function with(): array
    {
        if ($this->projectId !== null) {
            [$user, , $assignment] = ProjectMessaging::context($this->projectId);
            return ['unread' => $assignment->messages()->unreadFor($user)->count()];
        }
        return ['unread' => ProjectMessaging::totalUnread(ProjectMessaging::account())];
    }
}; ?>

<span wire:poll.15s.visible="refreshUnread" class="inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-slate-200 px-1.5 text-xs font-semibold tabular-nums text-slate-900 dark:bg-slate-700 dark:text-slate-100" aria-label="{{ $unread }} unread incoming {{ $unread === 1 ? 'message' : 'messages' }}">{{ $unread > 99 ? '99+' : $unread }}</span>
