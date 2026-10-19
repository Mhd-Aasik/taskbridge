@props(['title', 'description' => null])

<section {{ $attributes->class('tb-panel') }}>
    <div class="border-b border-slate-100 px-6 py-5 dark:border-slate-700/60">
        <h2 class="text-base font-semibold tracking-tight">{{ $title }}</h2>
        @if ($description)
            <p class="tb-muted mt-1 text-xs leading-5">{{ $description }}</p>
        @endif
    </div>
    {{ $slot }}
</section>
