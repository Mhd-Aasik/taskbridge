<x-layouts.auth>
    <x-auth-header title="Verify your email before signing in" description="Existing accounts keep their password. Request a verification link, then confirm ownership before login." />
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ route('verification.send') }}" class="flex flex-col gap-6">@csrf
        <flux:input name="email" label="Email address" type="email" required autocomplete="email" />
        <flux:button type="submit" variant="primary" class="w-full">Send verification email</flux:button>
    </form>
    <x-text-link href="{{ route('login') }}">Back to login</x-text-link>
    <x-text-link href="{{ route('register') }}">New account? Request a setup link</x-text-link>
</x-layouts.auth>
