@props(['title' => null])

<x-layouts.app.sidebar :title="$title">
    <flux:main id="main-content" role="main" tabindex="-1" class="min-w-0 px-4! py-7! sm:px-6! lg:px-9! lg:py-9!">
        <div class="mx-auto w-full max-w-7xl">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts.app.sidebar>
