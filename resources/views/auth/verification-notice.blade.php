<x-layouts.auth>
    <x-auth-header title="Verify your email" description="Your password is unchanged. Verify ownership of your email before entering the workspace." />
    @if (session('status') === 'verification-link-sent')<p role="status">A verification link has been sent to your current email address.</p>@endif
    @if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ route('verification.send') }}">@csrf<flux:button type="submit" variant="primary" class="w-full">Send verification email</flux:button></form>
    <form method="POST" action="{{ route('logout') }}">@csrf<flux:button type="submit" class="w-full">Log out</flux:button></form>
</x-layouts.auth>
