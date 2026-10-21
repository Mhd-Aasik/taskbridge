<?php

use App\Support\ProjectMessaging;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('Project conversations')] class extends Component {
    use WithPagination;

    public function refreshConversations(): void { ProjectMessaging::assignments(ProjectMessaging::account()); }

    public function with(): array
    {
        $user = ProjectMessaging::account();
        $query = ProjectMessaging::assignments($user);
        $validator = Validator::make(['page' => $this->paginators['page'] ?? request()->query('page', 1)], ['page' => ['required', 'integer', 'min:1', 'max:10000']]);
        abort_if($validator->fails(), 422, 'Choose a conversation page between 1 and 10,000.');
        return ['conversations' => $query->with(['project:id,title,client_id,status', 'project.client:id,name', 'freelancer:id,name'])
            ->withCount(['messages as unread_messages_count' => fn ($messages) => $messages->unreadFor($user)])
            ->orderByDesc('assigned_at')->orderByDesc('id')->paginate(10), 'viewer' => $user, 'totalUnread' => ProjectMessaging::totalUnread($user)];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-5xl space-y-6" wire:poll.15s.visible="refreshConversations">
    <div><p class="tb-eyebrow mb-2">Your assigned projects</p><h1 class="text-2xl font-bold sm:text-3xl">Project conversations</h1><p class="tb-muted mt-2">{{ $totalUnread }} unread incoming {{ $totalUnread === 1 ? 'message' : 'messages' }} across your conversations.</p></div>
    <x-dashboard.panel title="Private conversations">
        @if ($conversations->isEmpty())
            <x-dashboard.empty-state icon="chat-bubble-left-right" title="No project conversations yet." description="Your conversations appear here when a project has an assigned Freelancer." />
        @else
            <ol class="divide-y divide-slate-200 dark:divide-slate-700" aria-label="Your project conversations">
                @foreach ($conversations as $conversation)
                    <li class="space-y-3 p-5 sm:p-6" wire:key="conversation-{{ $conversation->id }}">
                        <h2 class="break-words text-lg font-semibold"><a class="tb-text-link" href="{{ route('projects.messages', $conversation->project_id) }}" wire:navigate>{{ $conversation->project->title }}</a></h2>
                        <p class="tb-muted break-words">{{ $viewer->isClient() ? $conversation->freelancer->name : $conversation->project->client->name }} · {{ Str::headline($conversation->project->status->value) }}</p>
                        <p class="font-medium">{{ $conversation->unread_messages_count }} unread incoming {{ $conversation->unread_messages_count === 1 ? 'message' : 'messages' }}</p>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-dashboard.panel>
    {{ $conversations->links() }}
</div>
