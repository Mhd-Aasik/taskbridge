<?php

use App\Actions\Messages\ReadProjectMessages;
use App\Actions\Messages\SendProjectMessage;
use App\Models\Project;
use App\Support\ConversationHistory;
use App\Support\MessagePresentation;
use App\Support\ProjectMessaging;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

new #[Layout('components.layouts.app'), Title('Project messages')] class extends Component {
    #[Locked]
    public int $projectId;
    #[Locked]
    public string $submissionId;
    #[Locked]
    public array $messageIds = [];
    #[Locked]
    public bool $viewingOlder = false;
    public $body = '';
    public $followLatest = true;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->submissionId = (string) Str::uuid();
        [$user, , $assignment] = ProjectMessaging::context($this->projectId);
        $this->messageIds = ConversationHistory::initial($assignment, $user);
    }

    public function sendMessage(): void
    {
        $this->resetValidation();
        $message = $this->performMessageAction(fn () => app(SendProjectMessage::class)->send($this->projectId, ['body' => $this->body], $this->submissionId), 'body');
        if (! $message) { return; }
        [, , $assignment] = ProjectMessaging::context($this->projectId);
        $this->messageIds = ConversationHistory::latest($assignment);
        $this->viewingOlder = false;
        $this->body = '';
        $this->submissionId = (string) Str::uuid();
        session()->flash('message-sent', 'Message sent.');
        $this->dispatch('project-message-sent');
        $this->dispatch('conversation-refreshed', scroll: 'sent');
    }

    public function renewSubmission(): void
    {
        ProjectMessaging::context($this->projectId);
        $this->submissionId = (string) Str::uuid();
        $this->resetValidation('message_conflict');
    }

    public function pollConversation(): void
    {
        [, , $assignment] = ProjectMessaging::context($this->projectId);
        $before = $this->messageIds;
        if ($this->followLatest === true && ! $this->viewingOlder) { $this->messageIds = ConversationHistory::newer($assignment, $this->messageIds); }
        $this->dispatch('conversation-refreshed', scroll: $before !== $this->messageIds ? 'following' : 'none');
    }

    public function loadOlder(): void
    {
        [, , $assignment] = ProjectMessaging::context($this->projectId);
        $this->messageIds = ConversationHistory::older($assignment, $this->messageIds);
        $this->viewingOlder = true;
    }

    public function loadNewer(): void
    {
        [, , $assignment] = ProjectMessaging::context($this->projectId);
        $this->messageIds = ConversationHistory::newer($assignment, $this->messageIds);
        $this->viewingOlder = $this->messageIds !== [] && $assignment->messages()->where('id', '>', max($this->messageIds))->exists();
        $this->dispatch('conversation-refreshed', scroll: 'requested');
    }

    public function goToUnread(): void
    {
        [$user, , $assignment] = ProjectMessaging::context($this->projectId);
        $this->messageIds = ConversationHistory::initial($assignment, $user);
        $this->viewingOlder = false;
        $this->dispatch('conversation-refreshed', scroll: 'requested');
    }

    public function markDisplayedRead(string $presentation): void
    {
        ProjectMessaging::context($this->projectId);
        $this->resetValidation('read');
        $this->performMessageAction(fn () => app(ReadProjectMessages::class)->mark($this->projectId, $presentation), 'read');
        $this->dispatch('project-message-read');
    }

    protected function performMessageAction(callable $action, string $field): mixed
    {
        try { return $action(); }
        catch (ValidationException|AuthorizationException|ModelNotFoundException|HttpExceptionInterface $exception) { throw $exception; }
        catch (Throwable $exception) {
            report($exception);
            $this->addError($field, 'We could not confirm this request. Your unsent text has been kept. Review the conversation or retry the same request.');
            return null;
        }
    }

    public function with(): array
    {
        [$user, $project, $assignment] = ProjectMessaging::context($this->projectId);
        $messages = $assignment->messages()->whereIn('id', $this->messageIds)->with('sender:id,name')->get();
        return ['project' => $project, 'assignment' => $assignment, 'viewer' => $user, 'messages' => $messages,
            'presentation' => MessagePresentation::issue($user, $assignment, $messages->modelKeys()),
            'unread' => $assignment->messages()->unreadFor($user)->count(),
            'earlierUnread' => $messages->isNotEmpty() && $assignment->messages()->unreadFor($user)->where('id', '<', $messages->first()->id)->exists(),
            'hasOlder' => $messages->isNotEmpty() && $assignment->messages()->where('id', '<', $messages->first()->id)->exists(),
            'hasNewer' => $messages->isNotEmpty() && $assignment->messages()->where('id', '>', $messages->last()->id)->exists(),
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6" wire:poll.10s.visible="pollConversation" data-presentation="{{ $presentation }}"
    x-data="{
        atBottom: true, reading: false, olderBusy: false,
        init() { this.$nextTick(() => { this.$refs.thread.scrollTop = this.$refs.thread.scrollHeight; this.acknowledge(); }); },
        track() {
            const thread = this.$refs.thread;
            this.atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight &lt; 48;
            this.$wire.followLatest = this.atBottom;
            this.acknowledge();
        },
        async acknowledge() {
            const end = this.$refs.end.getBoundingClientRect();
            const token = this.$el.dataset.presentation;
            if (this.reading || this.olderBusy || !this.atBottom || this.$wire.viewingOlder || document.visibilityState !== 'visible' || end.top &lt; 0 || end.bottom > window.innerHeight || !token) return;
            this.reading = true;
            try { await this.$wire.markDisplayedRead(token); } finally { this.reading = false; }
        },
        async older() {
            const thread = this.$refs.thread;
            const anchor = thread.querySelector('[data-message-id]');
            const anchorId = anchor?.dataset.messageId;
            const anchorOffset = anchor?.getBoundingClientRect().top;
            this.olderBusy = true;
            try {
                await this.$wire.loadOlder(); await this.$nextTick();
                const retained = thread.querySelector('[data-message-id=\'' + anchorId + '\']');
                if (retained) thread.scrollTop += retained.getBoundingClientRect().top - anchorOffset;
            } finally { this.olderBusy = false; }
            this.track();
        },
        refreshed(mode) {
            this.$nextTick(() => {
                if (mode === 'sent' || mode === 'requested' || (mode === 'following' &amp;&amp; this.atBottom &amp;&amp; !this.olderBusy)) {
                    this.$refs.thread.scrollTop = this.$refs.thread.scrollHeight; this.atBottom = true;
                }
                this.acknowledge();
            });
        }
    }"
    x-on:conversation-refreshed.window="refreshed($event.detail.scroll)"
    x-on:visibilitychange.document="acknowledge()" x-on:scroll.window.debounce.150ms="acknowledge()" x-on:resize.window.debounce.150ms="acknowledge()">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0"><p class="tb-eyebrow mb-2">Private project conversation</p><h1 class="break-words text-2xl font-bold sm:text-3xl">{{ $project->title }}</h1><p class="tb-muted mt-2">{{ $unread }} unread incoming {{ $unread === 1 ? 'message' : 'messages' }}</p></div>
        <flux:button :href="route('messages.index')" wire:navigate>All conversations</flux:button>
    </div>
    <p class="tb-editor-notice rounded-xl border border-teal-200 p-5 leading-7 dark:border-teal-900">Only the project’s Client and assigned Freelancer can send messages here. Authorised Admins may review this conversation when handling a project dispute. Messages are permanent; messaging does not reopen or change completed work.</p>
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('message-sent') }}</p>
    <flux:error name="read" />
    @if ($earlierUnread)<p class="tb-muted">Earlier unread messages remain outside this window. They have not been marked read.</p>@endif
    <div class="flex flex-wrap gap-3">
        @if ($hasOlder)<flux:button type="button" x-on:click="older()" wire:loading.attr="disabled" wire:target="loadOlder">Load older messages</flux:button>@endif
        @if ($hasNewer)<flux:button type="button" wire:click="loadNewer" wire:loading.attr="disabled" wire:target="loadNewer">Show newer messages</flux:button>@endif
        @if ($viewingOlder || $earlierUnread)<flux:button type="button" wire:click="goToUnread" wire:loading.attr="disabled" wire:target="goToUnread">{{ $unread ? 'Go to unread messages' : 'Go to latest messages' }}</flux:button>@endif
    </div>
    <section x-ref="thread" tabindex="0" aria-label="Project message history" x-on:scroll.debounce.150ms="track()" class="tb-panel h-[min(60dvh,40rem)] min-h-64 space-y-5 overflow-y-auto! overscroll-contain p-5 sm:p-6" style="overflow-anchor: none">
        @if ($messages->isEmpty())
            <x-dashboard.empty-state icon="chat-bubble-left-right" title="No messages yet." description="Start a private conversation about this assignment." />
        @else
            <ol class="space-y-5" aria-label="Messages in chronological order">
                @foreach ($messages as $message)
                    <li data-message-id="{{ $message->id }}" wire:key="message-{{ $message->id }}" class="space-y-2 rounded-xl border p-4 {{ $message->sender_id === $viewer->id ? 'border-teal-200 bg-teal-50 dark:border-teal-800 dark:bg-teal-950/40' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900' }}">
                        <p class="break-words font-semibold">{{ $message->sender->name }}{{ $message->sender_id === $viewer->id ? ' · You' : '' }}</p>
                        <p class="tb-muted text-sm"><time datetime="{{ $message->sent_at->toIso8601String() }}">{{ $message->sent_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</p>
                        <p class="whitespace-pre-wrap break-words leading-7 [overflow-wrap:anywhere]">{{ $message->body }}</p>
                    </li>
                @endforeach
            </ol>
        @endif
        <div x-ref="end" class="h-px" aria-hidden="true"></div>
    </section>
    <p role="status" aria-live="polite" wire:loading wire:target="loadOlder,loadNewer,goToUnread">Loading message history...</p>
    <form wire:submit="sendMessage" class="space-y-5" aria-label="Send a project message">
        <flux:textarea wire:model="body" label="Your message" description="Meaningful plain text, up to 5,000 characters. Press Enter for a new line; use Send message to send." rows="5" maxlength="5000" required />
        <flux:error name="message_conflict" />
        @error('message_conflict')<flux:button type="button" wire:click="renewSubmission" wire:loading.attr="disabled">Keep this text as a new message</flux:button>@enderror
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="sendMessage">Send message</flux:button>
        <p role="status" aria-live="polite" wire:loading wire:target="sendMessage">Sending message...</p>
    </form>
</div>
