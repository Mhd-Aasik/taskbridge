@props(['profile'])

<span {{ $attributes->class('relative inline-flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-teal-100 font-semibold text-teal-900 dark:bg-slate-700 dark:text-slate-100') }} x-data="{ failed: false }" aria-hidden="true">
    <span>{{ $profile['initials'] }}</span>
    @if ($profile['photo_url'])
        <img src="{{ $profile['photo_url'] }}" alt="" class="absolute inset-0 size-full object-cover" x-show="!failed" x-on:error="failed = true" loading="lazy" />
    @endif
</span>
