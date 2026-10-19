@props(['title' => null])

<x-layouts.app :title="$title">
    {{ $slot }}
</x-layouts.app>
