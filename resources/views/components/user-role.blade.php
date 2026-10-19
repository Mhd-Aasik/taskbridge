<flux:badge size="sm" color="teal" {{ $attributes }}>
    {{ __('Role: :role', ['role' => ucfirst(auth()->user()->role)]) }}
</flux:badge>
