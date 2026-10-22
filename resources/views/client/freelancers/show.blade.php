<x-layouts.app :title="$preview ? 'Preview your profile' : 'Freelancer profile'">
    <div class="tb-form-editor mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <x-dashboard.heading :title="$preview ? 'Preview your profile' : 'Freelancer profile'" :description="$preview ? 'The professional information Clients will see when your saved profile is published.' : 'Professional details shared by this Freelancer.'" />
            <flux:button :href="route($preview ? 'freelancer.profile.edit' : 'client.freelancers.index')" class="self-start shrink-0" wire:navigate>{{ $preview ? 'Edit profile and visibility' : 'All freelancers' }}</flux:button>
        </div>
        @if ($preview)<div class="tb-editor-notice rounded-xl border border-teal-200 p-5 leading-7 dark:border-teal-900">This is an owner-only preview of your saved profile. Publish from the editor to make it visible to authenticated Clients. Account photos require separate consent; saved changes to a published profile become visible to Clients.</div>@endif
        @if ($profile)
            <x-freelancers.profile :profile="$profile" />
            @unless ($preview)
                <x-dashboard.panel title="Start with a project brief"><div class="space-y-4 p-5 leading-7 sm:p-6"><p class="tb-muted">Post a project so Freelancers can submit proposals. Choose your collaborator through a submitted proposal with agreed fixed terms.</p><flux:button :href="route('client.projects.create')" variant="primary" wire:navigate>Post a project</flux:button></div></x-dashboard.panel>
            @endunless
        @else
            <x-dashboard.panel title="Your professional profile"><x-dashboard.empty-state icon="user-circle" title="Save your profile first." description="Add your professional details and skills in the editor, then return to preview them. New profiles remain private." /></x-dashboard.panel>
        @endif
    </div>
</x-layouts.app>
