@props(['record'])

@if ($record->isCompleted())
    <section aria-label="Recorded completion" {{ $attributes->class('tb-editor-notice space-y-2 rounded-xl border border-teal-200 p-5 dark:border-teal-900') }}>
        <p class="font-semibold">Completed by {{ $record->completer->name }}</p>
        <p class="tb-muted">Completed <time datetime="{{ $record->completed_at->toIso8601String() }}">{{ $record->completed_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</p>
        @if ($record->completion_note !== null && $record->completion_note !== '')
            <p class="whitespace-pre-wrap break-words leading-7">{{ $record->completion_note }}</p>
        @endif
        <p class="tb-muted text-sm leading-6">Completion records the Client’s outcome. It does not mark anything paid or acknowledge payment receipt.</p>
    </section>
@endif
