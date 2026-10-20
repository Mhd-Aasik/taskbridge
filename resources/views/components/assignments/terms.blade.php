@props(['assignment'])

<dl {{ $attributes->class('grid gap-5 sm:grid-cols-2') }}>
    <div><dt class="tb-muted text-sm">Agreed fixed budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $assignment->formattedBudget() }}</dd></div>
    <div><dt class="tb-muted text-sm">Agreed completion date</dt><dd class="mt-1 font-medium"><time datetime="{{ $assignment->agreed_deadline->toDateString() }}">{{ $assignment->agreed_deadline->format('j F Y') }}</time></dd></div>
    <div class="sm:col-span-2"><dt class="tb-muted text-sm">Assigned in Sri Lanka</dt><dd class="mt-1"><time datetime="{{ $assignment->assigned_at->setTimezone('Asia/Colombo')->toIso8601String() }}">{{ $assignment->assigned_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }} (Asia/Colombo)</time></dd></div>
</dl>
