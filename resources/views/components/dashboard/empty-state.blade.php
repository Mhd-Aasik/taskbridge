@props(['icon', 'title', 'description'])

<div class="flex flex-col items-center px-6 py-10 text-center sm:py-12">
    <div class="mb-5 flex size-16 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/70">
        <flux:icon :name="$icon" class="size-7 text-slate-500 dark:text-slate-300" />
    </div>
    <p class="tb-eyebrow mb-2">On the horizon</p>
    <h3 class="text-base font-semibold">{{ $title }}</h3>
    <p class="tb-muted mt-3 max-w-md text-sm leading-6">{{ $description }}</p>
</div>
