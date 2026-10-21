<?php

use App\Models\DisputeCase;
use App\Support\DisputeContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app'), Title('Dispute cases')] class extends Component {
    use WithPagination;
    #[Locked]
    public bool $adminContext = false;
    #[Url(except: '')]
    public $state = '';
    #[Url(except: 'newest')]
    public $order = 'newest';

    public function mount(bool $administration = false): void { $this->adminContext = $administration; }

    public function updated($property): void
    {
        if (in_array($property, ['state', 'order'], true)) { $this->resetPage(); $this->resetValidation(); }
    }

    public function clearFilters(): void { $this->state = ''; $this->order = 'newest'; $this->resetPage(); $this->resetValidation(); }

    public function with(): array
    {
        $user = DisputeContext::account();
        Gate::forUser($user)->authorize($this->adminContext ? 'viewAdminAny' : 'viewAny', DisputeCase::class);
        // Validate the raw page before Livewire's paginator normalises invalid input to 1.
        $validator = Validator::make(['state' => $this->state, 'order' => $this->order, 'page' => $this->paginators['page'] ?? request()->query('page', 1)], [
            'state' => ['nullable', 'string', 'in:open,resolved,dismissed'], 'order' => ['required', 'string', 'in:newest,oldest'], 'page' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        abort_if($validator->fails(), 422, 'Choose a valid case state, sort order and page between 1 and 10,000.');
        $filters = $validator->validated();
        $base = DisputeCase::query()->when(! $this->adminContext, fn ($query) => $query->forParticipant($user));
        $counts = ['total' => (clone $base)->count()];
        foreach (['open', 'resolved', 'dismissed'] as $state) { $counts[$state] = (clone $base)->inState($state)->count(); }
        $direction = $filters['order'] === 'oldest' ? 'asc' : 'desc';
        return [
            'counts' => $counts,
            'disputes' => $base->inState($filters['state'] ?? '')->with(['opener:id,name', 'assignment.project:id,title', 'resolution', 'activeRecord'])
                ->orderBy('opened_at', $direction)->orderBy('id', $direction)->paginate(10),
        ];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-5xl space-y-6">
    <div><p class="tb-eyebrow mb-2">{{ $adminContext ? 'Private administration' : 'Your private case history' }}</p><h1 class="text-2xl font-bold sm:text-3xl">{{ $adminContext ? 'Admin dispute cases' : 'Your disputes' }}</h1><p class="tb-muted mt-2">{{ $adminContext ? 'Review cases and private discussions. Admin decisions do not alter work or payment history.' : 'Cases for your own assignments, including resolved and dismissed history.' }}</p></div>
    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach (['total' => 'All cases', 'open' => 'Open', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'] as $key => $label)
            <div class="tb-panel p-5"><dt class="tb-muted">{{ $label }}</dt><dd class="mt-2 text-2xl font-semibold tabular-nums">{{ $counts[$key] }}</dd></div>
        @endforeach
    </dl>
    <div class="tb-panel grid gap-5 p-5 sm:grid-cols-2 sm:p-6">
        <flux:select wire:model.live="state" label="Case state"><option value="">All case states</option><option value="open">Open</option><option value="resolved">Resolved</option><option value="dismissed">Dismissed</option></flux:select>
        <flux:select wire:model.live="order" label="Sort cases"><option value="newest">Newest opening first</option><option value="oldest">Oldest opening first</option></flux:select>
        <flux:button type="button" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="state,order,clearFilters" class="self-start">Clear filters</flux:button>
    </div>
    <x-dashboard.panel title="Case history">
        @if ($disputes->isEmpty())
            <x-dashboard.empty-state icon="chat-bubble-left-right" :title="$counts['total'] ? 'No cases match these filters.' : 'No dispute cases yet.'" :description="$adminContext ? 'Assigned-project disputes will appear here for private review.' : 'Open a private dispute from an assigned project when you need an Admin to review a concern.'" />
        @else
            <ol class="divide-y divide-slate-200 dark:divide-slate-700" aria-label="Dispute cases">
                @foreach ($disputes as $dispute)
                    <li class="space-y-3 p-5 sm:p-6" wire:key="dispute-{{ $dispute->id }}">
                        <h2 class="break-words text-lg font-semibold"><a class="tb-text-link" href="{{ route($adminContext ? 'admin.disputes.show' : 'disputes.show', $dispute) }}" wire:navigate>#{{ $dispute->id }} · {{ $dispute->title }}</a></h2>
                        <p class="tb-muted break-words">{{ $dispute->assignment->project->title }} · {{ Str::headline($dispute->state()) }}</p>
                        <p class="tb-muted break-words">Opened by {{ $dispute->opener->name }} · <time datetime="{{ $dispute->opened_at->toIso8601String() }}">{{ $dispute->opened_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time> (Asia/Colombo)</p>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-dashboard.panel>
    {{ $disputes->links() }}
</div>
