<flux:badge size="sm" {{ $attributes }}>
    {{ __('Role: :role', ['role' => ucfirst(auth()->user()->role)]) }}
</flux:badge>
