<flux:dropdown position="bottom" align="end" x-data>
    <flux:button
        variant="subtle"
        square
        class="tb-theme-trigger shrink-0"
        aria-label="Choose theme"
        x-bind:aria-label="'Choose theme. Current preference: ' + $flux.appearance"
        title="Choose theme"
    >
        <flux:icon.sun class="size-5" x-show="$flux.appearance === 'light'" x-cloak aria-hidden="true" />
        <flux:icon.moon class="size-5" x-show="$flux.appearance === 'dark'" x-cloak aria-hidden="true" />
        <flux:icon.computer-desktop class="size-5" x-show="$flux.appearance === 'system'" x-cloak aria-hidden="true" />
    </flux:button>

    <flux:menu class="tb-theme-menu" aria-label="Theme">
        <flux:menu.radio.group x-model="$flux.appearance" aria-label="Theme preference">
            <flux:menu.radio value="light">Light</flux:menu.radio>
            <flux:menu.radio value="dark">Dark</flux:menu.radio>
            <flux:menu.radio value="system">System</flux:menu.radio>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
