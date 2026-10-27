<x-layouts.auth>
    <x-auth-header title="Finish your TaskBridge registration" description="Choose a password to verify your email and complete account setup." />
    <p class="text-sm text-slate-600 dark:text-slate-300">{{ $registration->name }} · {{ ucfirst($registration->role) }}</p>
    <noscript>Enable JavaScript to open the secure setup link from your email.</noscript>
    <form method="POST" action="{{ route('registration.complete', $registration->id) }}" data-account-link="setup" class="flex flex-col gap-6">
        @csrf
        <input type="hidden" name="setup_token" value="{{ $setupToken ?? '' }}" data-link-token autocomplete="off">
        <flux:input name="email" label="Email address" type="email" value="{{ $registration->email }}" readonly required />
        <flux:input name="password" label="Password" type="password" autocomplete="new-password" required minlength="12" />
        <flux:input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required minlength="12" />
        <p class="text-sm text-slate-600 dark:text-slate-300">Use at least 12 characters with upper/lowercase letters, a number and a symbol (maximum 72 bytes).</p>
        @if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p data-link-error hidden role="alert">Open the complete link from your setup email, or request a new link.</p>
        <flux:button type="submit" variant="primary">Complete registration</flux:button>
    </form>
    <x-text-link href="{{ route('register') }}">Request a new setup email</x-text-link>
    @vite('resources/js/account-link.js')
</x-layouts.auth>
