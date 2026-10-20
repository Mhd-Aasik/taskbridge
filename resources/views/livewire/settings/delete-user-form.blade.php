<?php

use App\Livewire\Actions\Logout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        abort_unless(Auth::check(), 403);

        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        DB::transaction(function (): void {
            $user = User::query()->lockForUpdate()->findOrFail(Auth::id());
            if ($user->projects()->exists()) {
                throw ValidationException::withMessages([
                    'password' => __('Your account cannot be deleted because it has projects. Project history must be preserved.'),
                ]);
            }
            if ($user->submittedProposals()->exists()) {
                throw ValidationException::withMessages([
                    'password' => __('Your account cannot be deleted because it has proposals. Proposal history must be preserved.'),
                ]);
            }
            if ($user->createdMilestones()->exists()) {
                throw ValidationException::withMessages([
                    'password' => __('Your account cannot be deleted because it has milestone history. Planning history must be preserved.'),
                ]);
            }
            if ($user->createdTasks()->exists()) {
                throw ValidationException::withMessages([
                    'password' => __('Your account cannot be deleted because it has task history. Project history must be preserved.'),
                ]);
            }
            if ($user->createdDeliverables()->exists() || $user->uploadedDeliverableVersions()->exists()) {
                throw ValidationException::withMessages([
                    'password' => __('Your account cannot be deleted because it has deliverable history. Submitted files and project history must be preserved.'),
                ]);
            }

            if (! $user->delete()) {
                throw new RuntimeException('The account could not be deleted.');
            }
        });
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Delete Account') }}</flux:heading>
        <flux:subheading>{{ __('Delete your account and all of its resources') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" class="tb-danger-action" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
            {{ __('Delete Account') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

                <flux:subheading>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="password" id="password" label="{{ __('Password') }}" type="password" name="password" />

            <div class="flex justify-end space-x-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" class="tb-danger-action">{{ __('Delete Account') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
