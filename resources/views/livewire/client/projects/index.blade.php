<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('Your projects')] class extends Component {
    use WithPagination;

    public function with(): array
    {
        $user = User::query()->find(Auth::id());
        abort_unless($user, 403);
        Gate::forUser($user)->authorize('viewAny', Project::class);

        return [
            'projects' => $user->projects()->with('category')->withCount('proposals')->latest('updated_at')->latest('id')->paginate(10),
        ];
    }
}; ?>

<div class="mx-auto max-w-5xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="tb-eyebrow mb-2">Client workspace</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Your projects</h1>
            <p class="tb-muted mt-2 text-base leading-7">{{ $projects->total() }} {{ Str::plural('project', $projects->total()) }} in your workspace. Drafts are private and are not available to freelancers.</p>
        </div>
        <flux:button :href="route('client.projects.create')" variant="primary" icon="plus" class="self-start" wire:navigate>Create project</flux:button>
    </div>

    @if ($projects->isEmpty())
        <x-dashboard.panel title="Start with a clear brief" description="Make space for your next collaboration.">
            <x-dashboard.empty-state icon="folder-plus" title="Your projects will appear here." description="Create a private draft to capture your goals, category, budget, and deadline. Review it and publish when you are ready." />
            <div class="flex justify-center px-6 pb-8">
                <flux:button :href="route('client.projects.create')" variant="primary" wire:navigate>Create project</flux:button>
            </div>
        </x-dashboard.panel>
    @else
        <div class="space-y-4" aria-label="Your project list">
            @foreach ($projects as $project)
                <x-projects.card :project="$project" wire:key="project-{{ $project->id }}" />
            @endforeach
        </div>
        {{ $projects->links() }}
    @endif
</div>
