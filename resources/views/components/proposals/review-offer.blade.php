@props(['proposal', 'project'])

<dl {{ $attributes->class('grid gap-5 sm:grid-cols-2') }}>
    <div><dt class="tb-muted text-sm">Offered fixed budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $proposal->formattedBudget() }}</dd></div>
    <div><dt class="tb-muted text-sm">Advertised project budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $project->formattedBudget() }}</dd></div>
    <div><dt class="tb-muted text-sm">Proposed completion date</dt><dd class="mt-1">@if ($offerDeadline = $proposal->calendarDeadline())<time datetime="{{ $offerDeadline->toDateString() }}">{{ $offerDeadline->format('j F Y') }}</time>@else Completion date needs correction. @endif</dd></div>
    <div><dt class="tb-muted text-sm">Submitted in Sri Lanka</dt><dd class="mt-1"><time datetime="{{ $proposal->submitted_at->setTimezone('Asia/Colombo')->toIso8601String() }}">{{ $proposal->submitted_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }} (Asia/Colombo)</time></dd></div>
</dl>
