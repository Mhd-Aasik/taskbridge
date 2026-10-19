@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-[#0c1422] dark:text-slate-100">
        <a href="#main-content" class="tb-skip-link">Skip to content</a>

        <flux:sidebar sticky collapsible="mobile" class="tb-sidebar dark h-dvh border-r border-slate-800 bg-[#101f35] text-white lg:w-64">
            <div class="flex shrink-0 items-center justify-between gap-3 px-1 pb-4 pt-3">
                <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 rounded-lg" aria-label="TaskBridge dashboard" wire:navigate>
                    <x-app-logo sidebar />
                </a>
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                <x-workspace-navigation />
            </div>

            <div class="shrink-0 border-t border-slate-700/70 px-1 pt-5">
                <a href="{{ route('settings.profile') }}" class="flex min-w-0 items-center gap-3 rounded-lg p-1" aria-label="Your profile settings" wire:navigate>
                    <x-avatar :user="auth()->user()" class="size-10 rounded-xl bg-slate-700 text-sm font-semibold text-teal-200" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</span>
                        <span class="block truncate text-xs text-slate-400">{{ auth()->user()->email }}</span>
                    </span>
                    <flux:icon.chevron-right class="size-4 shrink-0 text-slate-400" />
                </a>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <flux:button type="submit" variant="subtle" icon="arrow-right-start-on-rectangle" class="w-full justify-start! text-slate-300!">Log out</flux:button>
                </form>
            </div>
        </flux:sidebar>

        <flux:header class="min-w-0 gap-3 border-b border-slate-200 bg-white px-4! py-4 sm:px-6! lg:px-9! dark:border-slate-800 dark:bg-[#101b2c]">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">{{ $title ?? 'Account settings' }}</p>
                <div class="mt-1 flex items-center gap-3">
                    <x-user-role class="shrink-0" />
                    <p class="hidden text-xs text-slate-500 md:block dark:text-slate-400">Your TaskBridge workspace</p>
                </div>
            </div>
            <x-theme-dropdown />
            <a href="{{ route('settings.profile') }}" class="flex size-9 shrink-0 items-center justify-center rounded-full border border-teal-200 bg-teal-50 text-xs font-bold text-teal-800 dark:border-teal-800 dark:bg-teal-950 dark:text-teal-200" aria-label="Profile settings" wire:navigate><x-avatar :user="auth()->user()" class="size-full rounded-full" /></a>
        </flux:header>

        {{ $slot }}
        @fluxScripts
    </body>
</html>
