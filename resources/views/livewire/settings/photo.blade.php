<?php

use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $photo = null;

    #[Locked]
    public bool $previewReady = false;

    #[Locked]
    public string $message = '';

    public function mount(): void
    {
        $this->account();
    }

    public function updatedPhoto(): void
    {
        $this->account();
        $this->previewReady = false;
        $this->message = '';
        $this->resetValidation('photo');
        if ($this->photo !== null) {
            app(AccountPhotos::class)->prepare($this->photo);
            $this->previewReady = true;
        }
    }

    public function savePhoto(AccountPhotos $photos): void
    {
        $account = $this->account();
        $this->message = '';
        try {
            $account = $photos->replace($account, $this->photo);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('photo', 'We could not save your photo. Your current photo has been kept. Please try again.');

            return;
        }
        $this->finish($account, 'Profile photo saved.');
    }

    public function removePhoto(AccountPhotos $photos): void
    {
        $account = $this->account();
        $this->message = '';
        try {
            $account = $photos->remove($account);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('photo', 'We could not remove your photo. Please try again.');

            return;
        }
        $this->finish($account, 'Profile photo removed.');
    }

    protected function account(): User
    {
        $account = User::query()->find(Auth::id());
        abort_unless($account, 403);
        Gate::authorize('updatePhoto', $account);

        return $account;
    }

    protected function finish(User $account, string $message): void
    {
        if ($this->photo instanceof TemporaryUploadedFile) {
            try {
                $this->photo->delete();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $this->reset('photo', 'previewReady');
        $this->resetValidation();
        $this->message = $message;
        Auth::setUser($account);
        $this->dispatch('account-photo-updated', userId: $account->getKey(), url: $account->avatarUrl());
    }

    public function with(): array
    {
        return ['account' => $this->account()];
    }
}; ?>

<section
    class="my-6 space-y-5 rounded-xl border border-slate-200 p-5 dark:border-slate-700"
    aria-labelledby="photo-heading"
    x-data="{ preview: null, uploading: false, progress: 0, uploadError: '' }"
    x-on:account-photo-updated.window="if (preview) URL.revokeObjectURL(preview); preview = null; $refs.photo.value = ''"
    x-on:livewire-upload-start="uploading = true; progress = 0; uploadError = ''"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false; uploadError = 'The upload failed. Please choose the photo again.'"
    x-on:livewire-upload-cancel="uploading = false"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
>
    <div>
        <h2 id="photo-heading" class="text-base font-semibold">Profile photo</h2>
        <p class="tb-muted mt-1 text-sm leading-6">Your photo is private and visible only to you for now. Photo changes are saved separately from your name and email.</p>
    </div>

    <div class="flex flex-wrap items-start gap-5">
        <div class="space-y-2">
            <x-avatar :user="$account" class="size-20 rounded-2xl bg-teal-50 text-xl font-semibold text-teal-800 dark:bg-teal-950 dark:text-teal-200" />
            <p class="tb-muted text-sm">Current photo</p>
        </div>
        <template x-if="preview && $wire.previewReady && !uploading">
            <div class="space-y-2">
                <img x-bind:src="preview" alt="New profile photo preview" width="80" height="80" class="size-20 rounded-2xl object-cover" />
                <p class="tb-muted text-sm">Upload preview</p>
            </div>
        </template>
    </div>

    <form wire:submit="savePhoto" class="space-y-4">
        <flux:field>
            <flux:label for="profile-photo-upload">Choose a profile photo</flux:label>
            <input
                id="profile-photo-upload"
                x-ref="photo"
                wire:model="photo"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                aria-describedby="photo-help photo-error"
                @error('photo') aria-invalid="true" @enderror
                data-flux-control
                class="block w-full rounded-lg border border-slate-400 bg-white p-3 text-base text-slate-900 file:me-3 file:rounded-md file:border-0 file:bg-teal-700 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:border-slate-500 dark:bg-[#142033] dark:text-slate-100 dark:file:bg-teal-300 dark:file:text-teal-950"
                wire:loading.attr="disabled"
                wire:target="savePhoto,removePhoto"
                x-on:change="if (preview) URL.revokeObjectURL(preview); preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
            />
            <flux:description id="photo-help">JPEG, PNG, or WebP. Up to 2 MB and 4096 × 4096 pixels.</flux:description>
            <flux:error name="photo" id="photo-error" />
        </flux:field>
        <p x-show="uploadError" x-cloak role="alert" class="text-sm font-medium text-red-700 dark:text-red-300" x-text="uploadError"></p>
        <p x-show="uploading" x-cloak role="status" class="text-sm text-slate-700 dark:text-slate-200" x-text="'Uploading photo… ' + progress + '%'"></p>
        <p wire:loading wire:target="savePhoto,removePhoto" role="status" class="text-sm text-slate-700 dark:text-slate-200">Updating profile photo…</p>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary" :disabled="! $previewReady" x-bind:disabled="uploading || ! $wire.previewReady" wire:loading.attr="disabled" wire:target="photo,savePhoto,removePhoto">Save photo</flux:button>
            <flux:button type="button" wire:click="removePhoto" :disabled="! $account->avatar_path" x-bind:disabled="uploading || @js(! $account->avatar_path)" wire:loading.attr="disabled" wire:target="photo,savePhoto,removePhoto">Remove photo</flux:button>
        </div>
        <p role="status" aria-live="polite" aria-atomic="true" class="text-sm font-medium text-teal-800 dark:text-teal-200">{{ $message }}</p>
    </form>
</section>
