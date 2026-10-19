@props(['sidebar' => false])

<span @class(['flex size-9 shrink-0 items-center justify-center', 'lg:size-16' => $sidebar])>
    <x-app-logo-icon class="size-full" />
</span>
<span class="min-w-0 text-left">
    <span class="block text-base font-bold tracking-[-0.45px]">TaskBridge<span class="text-teal-300">.</span></span>
    <span class="block text-[9px] font-medium uppercase tracking-[2px] text-slate-400">
        <span class="block whitespace-nowrap">Better work.</span>
        <span class="block">Together</span>
    </span>
</span>
