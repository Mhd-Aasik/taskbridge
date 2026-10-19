<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="bg-muted relative hidden h-full flex-col p-10 text-white lg:flex dark:border-r dark:border-neutral-800">
                <div class="absolute inset-0 bg-neutral-900"></div>
                <a href="{{ route('home') }}" class="relative z-20 inline-flex items-center gap-3 self-start rounded-lg" aria-label="TaskBridge home" wire:navigate>
                    <x-app-logo />
                </a>

                @php
                    [$message, $author] = str(Illuminate\Foundation\Inspiring::quotes()->random())->explode('-');
                @endphp

                <div class="relative z-20 mt-auto">
                    <blockquote class="space-y-2">
                        <p class="text-lg">&ldquo;{{ trim($message) }}&rdquo;</p>
                        <footer class="text-sm">{{ trim($author) }}</footer>
                    </blockquote>
                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ route('home') }}" class="z-20 inline-flex items-center gap-3 self-center rounded-lg text-slate-900 lg:hidden dark:text-white" aria-label="TaskBridge home" wire:navigate>
                        <x-app-logo />
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
