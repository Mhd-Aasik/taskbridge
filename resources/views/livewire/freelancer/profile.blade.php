<?php

use App\Actions\Profiles\ChangeProfileVisibility;
use App\Models\Skill;
use App\Support\FreelancerProfileRules;
use App\Support\FreelancerProfiles;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Freelancer profile')] class extends Component {
    public string $headline = '';
    public string $bio = '';
    public string $location = '';
    public string $portfolio_url = '';
    public string $hourly_rate = '';
    public array $skill_ids = [];
    public bool $shareAccountPhoto = false;

    #[Locked]
    public string $visibilityRevision = '';

    #[Locked]
    public string $visibilityFeedback = '';

    #[Locked]
    public bool $saved = false;

    public function mount(): void
    {
        [, $profile] = $this->authorisedProfile();

        if ($profile) {
            $this->headline = $profile->headline ?? '';
            $this->bio = $profile->bio ?? '';
            $this->location = $profile->location ?? '';
            $this->portfolio_url = $profile->portfolio_url ?? '';
            $this->hourly_rate = $profile->hourly_rate ?? '';
            $this->skill_ids = $profile->skills()->pluck('skills.id')->all();
            $this->shareAccountPhoto = $profile->share_account_photo;
            $this->visibilityRevision = FreelancerProfiles::revision($profile);
        }
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function save(): void
    {
        $this->resetValidation();
        $this->saved = false;
        $this->authorisedProfile();

        $validated = FreelancerProfileRules::validate($this->only(['headline', 'bio', 'location', 'portfolio_url', 'hourly_rate', 'skill_ids']));

        DB::transaction(function () use ($validated): void {
            [$user, $profile] = $this->authorisedProfile(lock: true);
            if (($profile && ! hash_equals(FreelancerProfiles::revision($profile), $this->visibilityRevision)) || (! $profile && $this->visibilityRevision !== '')) {
                throw ValidationException::withMessages(['visibility' => 'Your saved profile or visibility changed. Reload before saving; your entered values have been retained.']);
            }
            $details = Arr::only($validated, ['headline', 'bio', 'location', 'portfolio_url', 'hourly_rate']);
            foreach (['location', 'portfolio_url', 'hourly_rate'] as $field) {
                $details[$field] = $details[$field] === '' ? null : $details[$field];
            }

            if ($profile) {
                $profile->fill($details);
                $profile->profile_version++;
                if (! $profile->saveOrFail()) {
                    throw new \RuntimeException('The professional profile could not be saved.');
                }
            } else {
                $profile = $user->freelancerProfile()->create($details);
            }

            $profile->skills()->sync($validated['skill_ids']);
        });

        $this->saved = true;
        [, $profile] = $this->authorisedProfile();
        $this->visibilityRevision = FreelancerProfiles::revision($profile);
    }

    protected function authorisedProfile(bool $lock = false): array
    {
        return FreelancerProfiles::owned($lock);
    }

    public function publish(): void
    {
        $this->resetValidation();
        $this->visibilityFeedback = '';
        $profile = app(ChangeProfileVisibility::class)->publish($this->visibilityRevision);
        $this->visibilityRevision = FreelancerProfiles::revision($profile);
        $this->visibilityFeedback = 'Your saved profile is now visible to Clients.';
    }

    public function unpublish(): void
    {
        $this->resetValidation();
        $this->visibilityFeedback = '';
        $profile = app(ChangeProfileVisibility::class)->unpublish($this->visibilityRevision);
        $this->visibilityRevision = FreelancerProfiles::revision($profile);
        $this->visibilityFeedback = 'Your profile is private. Clients can no longer browse it or request its shared photo.';
    }

    public function savePhotoPreference(): void
    {
        $this->resetValidation();
        $this->visibilityFeedback = '';
        $profile = app(ChangeProfileVisibility::class)->photoConsent($this->shareAccountPhoto, $this->visibilityRevision);
        $this->visibilityRevision = FreelancerProfiles::revision($profile);
        $this->visibilityFeedback = $profile->share_account_photo ? 'Photo sharing enabled for your visible-to-Clients profile.' : 'Photo sharing disabled. Clients will see your initials.';
    }

    public function with(): array
    {
        [, $profile] = $this->authorisedProfile();

        return [
            'skills' => Skill::query()->orderBy('name')->get(['id', 'name']),
            'isPublic' => $profile?->is_public ?? false,
            'hasProfile' => $profile !== null,
        ];
    }
}; ?>

<div class="tb-profile-editor mx-auto max-w-5xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="tb-eyebrow mb-2">Your workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Freelancer profile</h1>
            <p class="tb-muted mt-2 max-w-xl text-sm leading-6">Introduce your work and the skills you bring to a collaboration.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            @if ($hasProfile)<flux:button :href="route('freelancer.profile.preview')" wire:navigate>Preview profile</flux:button>@endif
            <flux:button :href="route('settings.profile')" icon="cog-6-tooth" class="self-start" wire:navigate>Account settings</flux:button>
        </div>
    </div>

    <div class="tb-profile-notice flex items-start gap-3 rounded-xl border border-teal-200 p-4 dark:border-teal-900">
        <flux:icon.lock-closed class="mt-0.5 size-5 shrink-0" />
        <p class="text-sm leading-6"><strong>Profile visibility: {{ $isPublic ? 'Visible to Clients' : 'Private' }}.</strong> New profiles are private. @if ($isPublic) Saved changes to your published profile become visible to authenticated Clients. @else Saving here does not publish your profile. @endif</p>
    </div>

    <x-dashboard.panel title="Profile visibility" description="Choose when authenticated Clients can discover your professional profile.">
        <div class="space-y-5 p-5 sm:p-6">
            <p class="tb-muted leading-7">Clients see your display name, headline, biography, skills, optional location, hourly rate, portfolio link, and released Freelancer reputation. Your account photo is shared only with your separate consent. This does not create an anonymously accessible directory.</p>
            <p class="tb-muted leading-7">Preview and publish your saved profile. Save any edits below before publishing.</p>
            <div class="flex flex-wrap gap-3">
                @if ($isPublic)
                    <flux:button wire:click="unpublish" wire:loading.attr="disabled" wire:target="publish,unpublish,savePhotoPreference,save">Unpublish profile</flux:button>
                @else
                    <flux:button wire:click="publish" variant="primary" wire:confirm="Publish your saved professional profile to authenticated Clients?" wire:loading.attr="disabled" wire:target="publish,unpublish,savePhotoPreference,save" :disabled="! $hasProfile">Publish profile</flux:button>
                @endif
                <span role="status" class="tb-muted" wire:loading wire:target="publish,unpublish,savePhotoPreference">Updating profile preference…</span>
            </div>
            <flux:error name="visibility" />
            <form wire:submit="savePhotoPreference" class="space-y-3 border-t border-slate-200 pt-5 dark:border-slate-700">
                <flux:checkbox wire:model="shareAccountPhoto" label="Show my account photo to Clients" :disabled="! $hasProfile" />
                <p class="tb-muted text-sm leading-6">Optional and off by default. While enabled, replacing your account photo also updates the photo shared on your published profile. Removing consent or unpublishing stops subsequent Client photo requests.</p>
                <flux:button type="submit" wire:loading.attr="disabled" wire:target="publish,unpublish,savePhotoPreference,save" :disabled="! $hasProfile">Save photo preference</flux:button>
            </form>
            <p role="status" aria-live="polite" aria-atomic="true" class="tb-profile-confirmation">{{ $visibilityFeedback }}</p>
        </div>
    </x-dashboard.panel>

    <form wire:submit="save" class="space-y-6">
        <fieldset wire:loading.attr="disabled" wire:target="save" class="min-w-0 space-y-6">
            <legend class="sr-only">Edit your freelancer profile</legend>
            <x-dashboard.panel title="Tell collaborators about yourself" description="Your professional details, separate from your account settings.">
                <div class="space-y-6 p-5 sm:p-6">
                    <flux:input wire:model="headline" label="Professional headline" description="A short introduction to what you do. Maximum 120 characters." maxlength="120" required autocomplete="organization-title" />
                    <flux:textarea wire:model.blur="bio" label="Biography" description="Describe your experience and approach. Plain text only, up to 2,000 characters." rows="7" maxlength="2000" required />
                    @if ($bio !== '')
                        <div class="tb-profile-preview rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                            <h2 class="tb-eyebrow mb-2">Biography preview</h2>
                            <p class="whitespace-pre-wrap break-words text-sm leading-6">{{ $bio }}</p>
                        </div>
                    @endif
                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="location" label="Location" badge="Optional" maxlength="120" autocomplete="address-level2" placeholder="City or region" />
                        <flux:input wire:model="hourly_rate" label="Hourly rate (LKR)" badge="Optional" type="number" min="0" max="9999999999.99" step="0.01" description="Sri Lankan rupees per hour, with up to two decimal places." />
                    </div>
                    <flux:input wire:model="portfolio_url" label="Portfolio URL" badge="Optional" type="url" maxlength="2048" autocomplete="url" placeholder="https://example.com" description="Use a complete HTTP or HTTPS address." />
                </div>
            </x-dashboard.panel>

            <x-dashboard.panel title="Your skills" description="Choose 1–10 skills that best describe your work.">
                <fieldset class="min-w-0 p-5 sm:p-6" aria-describedby="skills-help skills-errors">
                    <legend class="sr-only">Select your skills</legend>
                    <p id="skills-help" class="tb-muted mb-5 text-sm">Select at least one skill. You can change your selection whenever your focus changes.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @forelse ($skills as $skill)
                            <flux:checkbox wire:model="skill_ids" :value="$skill->id" :label="$skill->name" wire:key="profile-skill-{{ $skill->id }}" />
                        @empty
                            <p class="tb-muted text-sm sm:col-span-2">No skills are available yet. Please try again later.</p>
                        @endforelse
                    </div>
                    <div id="skills-errors" class="mt-4 space-y-1" aria-live="polite">
                        <flux:error name="skill_ids" />
                    </div>
                </fieldset>
            </x-dashboard.panel>
        </fieldset>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save" :disabled="$skills->isEmpty()">
                <span wire:loading.remove wire:target="save">Save profile</span>
                <span wire:loading wire:target="save">Saving profile…</span>
            </flux:button>
            <p role="status" aria-live="polite" aria-atomic="true" class="tb-profile-confirmation">
                @if ($saved)
                    Freelancer profile saved.
                @endif
            </p>
        </div>
    </form>
</div>
