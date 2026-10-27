<x-layouts.auth>
    <x-auth-header title="Confirm your email address" description="Your existing password will stay unchanged. Confirm ownership before signing in." />
    <form method="POST" action="{{ route('verification.complete', $verification->id) }}" data-account-link="verification" class="flex flex-col gap-6">
        @csrf
        <input type="hidden" name="token" value="{{ $token ?? '' }}" data-link-token autocomplete="off">
        <flux:input name="email" label="Email address" type="email" value="{{ $verification->email }}" readonly required />
        @if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p data-link-error hidden role="alert">Open the complete verification link from your email, or request a new one.</p>
        <flux:button type="submit" variant="primary">Confirm email ownership</flux:button>
    </form>
    <noscript>Enable JavaScript to open the secure verification link from your email.</noscript>
    <x-text-link href="{{ route('verification.notice') }}">Request a new verification link</x-text-link>
    @vite('resources/js/account-link.js')
</x-layouts.auth>
