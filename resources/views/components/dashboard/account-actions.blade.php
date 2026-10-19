<x-dashboard.panel title="Settle into your workspace">
    <div class="divide-y divide-slate-100 px-6 dark:divide-slate-700/60">
        <a href="{{ route('settings.password') }}" class="group flex items-center gap-3 rounded-lg py-5" wire:navigate>
            <flux:icon.shield-check class="size-5 shrink-0 text-slate-500 dark:text-slate-400" />
            <div class="min-w-0 flex-1"><p class="text-sm font-medium group-hover:text-teal-700 dark:group-hover:text-teal-300">Keep your account secure</p><p class="tb-muted mt-1 text-xs leading-5">Review and update your password.</p></div>
            <flux:icon.chevron-right class="size-4 shrink-0 text-slate-400" />
        </a>
    </div>
</x-dashboard.panel>
