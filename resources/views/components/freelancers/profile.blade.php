@props(['profile', 'compact' => false])

<article class="tb-panel space-y-5 p-5 sm:p-6">
    <div class="flex items-start gap-4">
        <x-freelancers.avatar :profile="$profile" />
        <div class="min-w-0 flex-1">
            <h2 class="break-words text-xl font-semibold">{{ $profile['name'] }}</h2>
            <p class="mt-1 break-words font-medium">{{ $profile['headline'] }}</p>
            @if ($profile['location'])<p class="tb-muted mt-2 break-words">{{ $profile['location'] }}</p>@endif
        </div>
    </div>
    <p class="whitespace-pre-wrap break-words leading-7">{{ $compact ? Str::limit($profile['bio'], 240) : $profile['bio'] }}</p>
    <ul class="flex flex-wrap gap-2" aria-label="Skills">
        @foreach ($profile['skills'] as $skill)<li class="rounded-lg border border-slate-200 px-3 py-1 text-sm font-medium dark:border-slate-600">{{ $skill['name'] }}</li>@endforeach
    </ul>
    <div class="flex flex-col gap-4 border-t border-slate-200 pt-5 sm:flex-row sm:items-start sm:justify-between dark:border-slate-700">
        <div><p class="tb-muted text-sm">Hourly rate</p><p class="mt-1 font-semibold tabular-nums">{{ $profile['formatted_rate'] ?? 'Not specified' }}</p></div>
        <x-feedback.reputation :summary="$profile['reputation']" />
    </div>
    @if ($compact)
        <flux:button :href="route('client.freelancers.show', $profile['id'])" :aria-label="'View profile of '.$profile['name']" wire:navigate>View profile</flux:button>
    @elseif ($profile['portfolio_url'])
        <a href="{{ $profile['portfolio_url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="inline-flex rounded-lg font-medium text-teal-800 underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600 dark:text-teal-300">Visit portfolio<span class="sr-only"> (opens in a new tab)</span></a>
    @endif
</article>
