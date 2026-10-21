@props(['summary'])

<div {{ $attributes->class(['text-sm leading-6']) }} data-reputation-role="{{ $summary['role'] }}">
    <p class="font-medium">Reputation as {{ ucfirst($summary['role']) }}</p>
    @if ($summary['count'])
        <p><span class="font-semibold tabular-nums">{{ $summary['average'] }} / 5</span> <span class="tb-muted">from {{ $summary['count'] }} released {{ Str::plural('rating', $summary['count']) }}</span></p>
    @else
        <p class="tb-muted">Not rated yet</p>
    @endif
</div>
