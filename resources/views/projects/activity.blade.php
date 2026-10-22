<x-layouts.app title="Project activity">
    <div class="tb-form-editor mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="tb-eyebrow mb-2">Private project workspace</p>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Project activity</h1>
                <p class="mt-2 break-words font-medium">{{ $project->title }}</p>
            </div>
            <flux:button :href="$user->isClient() ? route('client.projects.show', $project) : route('freelancer.assignments.show', $assignment)" icon="arrow-left" class="self-start shrink-0" wire:navigate>{{ $user->isClient() ? 'Project review' : 'Assigned project' }}</flux:button>
        </div>

        <div class="tb-editor-notice rounded-xl border border-teal-200 p-5 dark:border-teal-900">
            <p>{{ $user->isClient() ? 'Your project history includes private activity before assignment. Assigned Freelancers see shared activity from assignment onward.' : 'Shared activity is available from your assignment onward. Earlier Client activity remains private.' }}</p>
            <p class="mt-2">This history records effective changes from the activity feature onward. Detailed records remain on their dedicated pages. Task progress, deliverable approval, project completion and payment are separate.</p>
        </div>

        <x-dashboard.panel title="Activity history" description="Newest first · Times in Asia/Colombo">
            @if ($activities->isEmpty())
                <p class="tb-muted p-5 leading-7 sm:p-6">Activity will appear here as the project progresses.</p>
            @else
                <ol class="divide-y divide-slate-200 dark:divide-slate-800" aria-label="Project activity history">
                    @foreach ($activities as $activity)
                        <li class="space-y-2 p-5 sm:p-6">
                            <h2 class="font-semibold">{{ $activity->event_type->label() }}</h2>
                            <p class="break-words">{{ $activity->actor->name }}</p>
                            @if ($activity->event_type === App\Enums\ProjectActivityEvent::TaskStatusChanged)
                                <p class="tb-muted">Task status: {{ App\Enums\ProjectTaskStatus::from($activity->metadata['task_status'])->label() }}</p>
                            @endif
                            <p class="tb-muted text-sm"><time datetime="{{ $activity->occurred_at->toIso8601String() }}">{{ $activity->occurred_at->setTimezone('Asia/Colombo')->format('j F Y, g:i:s a') }}</time> (Asia/Colombo)</p>
                            @if ($destination = App\Support\ProjectActivityHistory::destination($user, $activity->event_type, $project, $assignment))
                                <flux:link :href="$destination" wire:navigate :aria-label="'View details: '.$activity->event_type->label()">View details</flux:link>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-dashboard.panel>
        {{ $activities->links() }}
    </div>
</x-layouts.app>
