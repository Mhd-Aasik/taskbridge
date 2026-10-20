@props(['existing' => false, 'milestones' => collect()])

<form wire:submit="save" class="space-y-5" aria-label="{{ $existing ? 'Submit a new deliverable version' : 'Submit a deliverable' }}"
    x-data="{ uploading: false, progress: 0, uploadError: '' }"
    x-on:livewire-upload-start="uploading = true; progress = 0; uploadError = ''"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false; uploadError = 'The upload failed. Please choose the file again.'"
    x-on:livewire-upload-cancel="uploading = false"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
    x-bind:aria-busy="uploading"
>
    <fieldset wire:loading.attr="disabled" wire:target="save,file" class="min-w-0" x-bind:disabled="uploading">
        <legend class="sr-only">{{ $existing ? 'New version details' : 'Deliverable details' }}</legend>
        <x-dashboard.panel :title="$existing ? 'Submit a new version' : 'Submit a deliverable'" :description="$existing ? 'Upload a separate file. Previous versions and their notes will remain unchanged.' : 'Share a file with your Client, optionally linked to a milestone.'">
            <div class="space-y-6 p-5 sm:p-6">
                @if (! $existing)
                    <flux:input wire:model="title" label="Deliverable title" description="Up to 150 characters." maxlength="150" required />
                    <flux:textarea wire:model="description" label="Description (optional)" description="Plain text, up to 5,000 characters." maxlength="5000" rows="4" />
                    <flux:select wire:model="milestone_id" label="Milestone (optional)">
                        <option value="">No milestone</option>
                        @foreach ($milestones as $milestone)
                            <option value="{{ $milestone->id }}">{{ $milestone->title }}</option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:field>
                    <flux:label id="deliverable-file-label" for="deliverable-file-upload">{{ $existing ? 'New version file' : 'Deliverable file' }}</flux:label>
                    <input id="deliverable-file-upload" wire:model="file" type="file" accept=".pdf,.txt,.jpg,.jpeg,.png,.webp,.zip,.docx,.xlsx,.pptx" required
                        aria-labelledby="deliverable-file-label" aria-describedby="deliverable-file-help deliverable-file-error" @error('file') aria-invalid="true" data-invalid @enderror
                        data-flux-control class="block w-full rounded-lg border border-slate-400 bg-slate-50 p-3 text-base text-slate-900 file:me-3 file:rounded-md file:border-0 file:bg-teal-700 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:border-slate-500 dark:bg-[#142033] dark:text-slate-100 dark:file:bg-teal-300 dark:file:text-teal-950" />
                    <flux:description id="deliverable-file-help">One PDF, TXT, JPEG, PNG, WebP, ZIP, DOCX, XLSX, or PPTX file. Maximum 10 MiB. Files are stored privately.</flux:description>
                    <flux:error name="file" id="deliverable-file-error" />
                </flux:field>
                <p role="status" aria-live="polite" x-show="uploading" x-cloak x-text="'Uploading file: ' + progress + '%'">Uploading file...</p>
                <p role="alert" class="text-red-800 dark:text-red-200" x-show="uploadError" x-cloak x-text="uploadError"></p>
                <flux:textarea wire:model="submission_note" label="Submission note (optional)" description="Explain this version in plain text, up to 5,000 characters." maxlength="5000" rows="4" />
                <p class="tb-muted leading-7">Submitted versions cannot be changed or deleted. Submission does not mean Client approval or project acceptance.</p>
            </div>
        </x-dashboard.panel>
    </fieldset>
    <div class="flex flex-wrap items-center gap-3">
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,file" x-bind:disabled="uploading">
            <span wire:loading.remove wire:target="save">{{ $existing ? 'Submit new version' : 'Submit deliverable' }}</span>
            <span wire:loading wire:target="save" role="status">Submitting file...</span>
        </flux:button>
        <span role="status" aria-live="polite" wire:loading wire:target="save">Saving your private file. Please wait.</span>
    </div>
</form>
