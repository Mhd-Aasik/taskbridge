@props(['messages'])

<x-dashboard.panel title="Current project conversation" description="Read-only conversation context for this dispute. Viewing here does not mark participants’ messages read.">
    @if ($messages->isEmpty())
        <p class="tb-muted p-5 sm:p-6">No project messages yet.</p>
    @else
        <ol class="space-y-5 p-5 sm:p-6" aria-label="Dispute conversation context">
            @foreach ($messages as $message)
                <li class="space-y-2 border-b border-slate-200 pb-5 last:border-0 dark:border-slate-700"><p class="break-words font-semibold">{{ $message->sender->name }}</p><p class="tb-muted text-sm">{{ $message->sent_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }} (Asia/Colombo)</p><p class="whitespace-pre-wrap break-words leading-7 [overflow-wrap:anywhere]">{{ $message->body }}</p></li>
            @endforeach
        </ol>
    @endif
    <div class="px-5 pb-5 sm:px-6">{{ $messages->links() }}</div>
</x-dashboard.panel>
