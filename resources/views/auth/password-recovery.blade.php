<x-layouts.auth>
    <noscript>Enable JavaScript to open the secure reset link from your email.</noscript>
    <x-auth-header title="Reset password" description="Choose and confirm your new password." />
    <form method="POST" action="{{ route('password.update') }}" data-account-link="reset" class="flex flex-col gap-6">
        @csrf
        <input type="hidden" name="token" value="{{ $token ?? '' }}" data-link-token autocomplete="off">
        <flux:input name="email" label="Email address" type="email" value="{{ $email ?? '' }}" required autocomplete="email" />
        <flux:input name="password" label="Password" type="password" required autocomplete="new-password" />
        <flux:input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        @if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p data-link-error hidden role="alert">Open the complete password-reset link from your email, or request a new one.</p>
        <flux:button type="submit" variant="primary">Reset password</flux:button>
    </form>
    <x-text-link href="{{ route('password.request') }}">Request a new reset email</x-text-link>
    @vite('resources/js/account-link.js')
</x-layouts.auth>
