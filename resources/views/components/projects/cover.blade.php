@props(['project', 'compact' => false])

@php($coverUrl = $project->coverUrl())

<div {{ $attributes->class(['relative isolate shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-teal-50 dark:border-slate-700 dark:bg-slate-900', 'aspect-[4/3] w-24 sm:w-32' => $compact, 'aspect-[16/7] max-h-64 w-full' => ! $compact]) }} x-data="{ imageFailed: false }" aria-hidden="true">
    <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-teal-50 via-slate-50 to-slate-100 dark:from-teal-950/50 dark:via-slate-900 dark:to-slate-800">
        <flux:icon.briefcase :class="$compact ? 'size-8 text-teal-700/70 dark:text-teal-300/70' : 'size-14 text-teal-700/70 dark:text-teal-300/70'" />
    </div>
    @if ($coverUrl !== null)
        <img src="{{ $coverUrl }}" alt="" width="{{ $compact ? 640 : 1600 }}" height="{{ $compact ? 480 : 700 }}" loading="lazy" decoding="async" class="absolute inset-0 h-full w-full object-cover" x-on:error="imageFailed = true" x-show="! imageFailed" />
    @endif
</div>
