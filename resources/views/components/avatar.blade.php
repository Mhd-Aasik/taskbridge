@props(['user'])

<span
    {{ $attributes->class('inline-flex shrink-0 items-center justify-center overflow-hidden') }}
    data-account-avatar
    x-data="{ url: @js($user->avatarUrl()), failed: false }"
    x-on:account-photo-updated.window="if ($event.detail.userId === @js($user->getKey())) { url = $event.detail.url; failed = false }"
>
    <img
        @if ($user->avatarUrl()) src="{{ $user->avatarUrl() }}" @endif
        x-bind:src="url || null"
        x-show="url && !failed"
        x-on:error="failed = true"
        x-cloak
        alt=""
        class="size-full object-cover"
    />
    <span x-show="!url || failed">{{ $user->initials() }}</span>
</span>
