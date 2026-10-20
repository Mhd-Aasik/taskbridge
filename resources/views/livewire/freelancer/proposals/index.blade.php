<?php

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('My proposals')] class extends Component {
    use WithPagination;

    public function with(): array
    {
        $user = User::query()->find(Auth::id());
        abort_unless($user, 403);
        Gate::forUser($user)->authorize('viewAny', Proposal::class);

        return ['proposals' => $user->submittedProposals()->with('project:id,title,cover_path')->latest('submitted_at')->latest('id')->paginate(10)];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-5xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <x-dashboard.heading title="My proposals" description="Your submitted offers and their current status." />
        <flux:button :href="route('freelancer.projects.index')" class="self-start shrink-0" wire:navigate>Browse projects</flux:button>
    </div>

    @if ($proposals->isEmpty())
        <x-dashboard.panel title="Your proposal history">
            <x-dashboard.empty-state icon="document-text" :title="$proposals->total() ? 'No proposals on this page.' : 'No proposals yet.'" description="Browse open projects to find a brief that fits your skills. Submitted proposals will appear here." />
        </x-dashboard.panel>
    @else
        <p class="tb-muted text-sm">{{ $proposals->total() }} {{ Str::plural('proposal', $proposals->total()) }}. Submission does not guarantee acceptance.</p>
        <div class="space-y-4" aria-label="Your proposal list">
            @foreach ($proposals as $proposal)
                <article class="tb-panel p-5 sm:p-6" wire:key="proposal-{{ $proposal->id }}">
                    <div class="flex items-start gap-4">
                        <x-projects.cover :project="$proposal->project" compact />
                        <div class="min-w-0">
                            <h2 class="break-words text-lg font-semibold"><a class="tb-text-link" href="{{ route('freelancer.proposals.show', $proposal) }}" wire:navigate>{{ $proposal->project->title }}</a></h2>
                            <p class="tb-muted mt-2 text-sm">Status: {{ Str::headline($proposal->status->value) }}</p>
                        </div>
                    </div>
                    <p class="tb-muted mt-3 whitespace-pre-wrap break-words leading-7">{{ Str::limit($proposal->cover_letter, 220) }}</p>
                    <dl class="mt-5 grid gap-5 sm:grid-cols-3">
                        <div><dt class="tb-muted text-sm">Your offered budget</dt><dd class="mt-1 font-medium tabular-nums">{{ $proposal->formattedBudget() }}</dd></div>
                        <div><dt class="tb-muted text-sm">Your completion date</dt><dd class="mt-1">{{ $proposal->proposed_deadline->format('j F Y') }}</dd></div>
                        <div><dt class="tb-muted text-sm">Submitted (Sri Lanka)</dt><dd class="mt-1">{{ $proposal->submitted_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</dd></div>
                    </dl>
                </article>
            @endforeach
        </div>
    @endif
    {{ $proposals->links() }}
</div>
