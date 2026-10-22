<?php

use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        }
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function save(): void
    {
        $this->saved = false;
        $this->authorisedProfile();

        $validated = $this->validate([
            'headline' => ['required', 'string', 'max:120'],
            'bio' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:120'],
            'portfolio_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'hourly_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'skill_ids' => ['required', 'array', 'min:1', 'max:10'],
            'skill_ids.*' => ['required', 'integer', 'distinct', Rule::exists(Skill::class, 'id')],
        ]);

        DB::transaction(function () use ($validated): void {
            [$user, $profile] = $this->authorisedProfile(lock: true);
            $details = Arr::only($validated, ['headline', 'bio', 'location', 'portfolio_url', 'hourly_rate']);
            foreach (['location', 'portfolio_url', 'hourly_rate'] as $field) {
                $details[$field] = $details[$field] === '' ? null : $details[$field];
            }

            if ($profile) {
                $profile->fill($details)->save();
            } else {
                $profile = $user->freelancerProfile()->create($details);
            }

            $profile->skills()->sync($validated['skill_ids']);
        });

        $this->saved = true;
    }

    protected function authorisedProfile(bool $lock = false): array
    {
        $users = User::query()->whereKey(Auth::id());
        if ($lock) {
            $users->lockForUpdate();
        }
        $user = $users->first();
        abort_unless($user, 403);
        \App\Support\AccountAccess::ensure($user);

        $profiles = $user->freelancerProfile();
        if ($lock) {
            $profiles->lockForUpdate();
        }
        $profile = $profiles->first();

        Gate::forUser($user)->authorize($profile ? 'update' : 'create', $profile ?? FreelancerProfile::class);

        return [$user, $profile];
    }

    public function with(): array
    {
        [, $profile] = $this->authorisedProfile();

        return [
            'skills' => Skill::query()->orderBy('name')->get(['id', 'name']),
            'isPublic' => $profile?->is_public ?? false,
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
        <flux:button :href="route('settings.profile')" icon="cog-6-tooth" class="self-start" wire:navigate>Account settings</flux:button>
    </div>

    <div class="tb-profile-notice flex items-start gap-3 rounded-xl border border-teal-200 p-4 dark:border-teal-900">
        <flux:icon.lock-closed class="mt-0.5 size-5 shrink-0" />
        <p class="text-sm leading-6"><strong>Profile visibility: {{ $isPublic ? 'Public' : 'Private' }}.</strong> New profiles are private. Saving here does not change your profile visibility.</p>
    </div>

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
