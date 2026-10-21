@props(['project', 'assignment', 'dispute', 'taskProgress', 'deliverables', 'adminContext'])

<section class="space-y-6" aria-label="Current case context">
    <div class="tb-editor-notice space-y-2 rounded-xl border border-teal-200 p-5 dark:border-teal-900"><h2 class="text-lg font-semibold">Current project context</h2><p class="leading-7">The information below shows the current project, work and payment records. It is not a snapshot from when the dispute was opened.</p></div>
    <x-dashboard.panel title="Current project and immutable agreed terms">
        <div class="space-y-5 p-5 sm:p-6"><h3 class="break-words text-lg font-semibold">{{ $project->title }}</h3><p class="tb-muted">Project status: {{ Str::headline($project->status->value) }}</p><p class="break-words">Client: {{ $project->client->name }} · Freelancer: {{ $assignment->freelancer->name }}</p><x-assignments.terms :assignment="$assignment" /><p class="whitespace-pre-wrap break-words leading-7">{{ $project->description }}</p><x-projects.completion :record="$project" /></div>
    </x-dashboard.panel>
    <x-dashboard.panel title="Current task progress" description="Saved task counts, independent of deliverable approval, project completion and payment."><x-tasks.progress :progress="$taskProgress" class="p-5 sm:p-6" /></x-dashboard.panel>
    <x-dashboard.panel title="Current external payment and receipt">
        <div class="space-y-5 p-5 sm:p-6">
            @if ($report = $assignment->paymentReport)
                <p class="font-semibold">Paid — reported by the Client</p>
                <dl class="grid gap-4 sm:grid-cols-2"><div><dt class="tb-muted">Full amount</dt><dd class="mt-1 font-medium tabular-nums">{{ $report->formattedAmount() }}</dd></div><div><dt class="tb-muted">Method and paid date</dt><dd class="mt-1">{{ $report->method->label() }} · {{ $report->paid_on->format('j F Y') }}</dd></div><div><dt class="tb-muted">Reference</dt><dd class="mt-1 break-words">{{ $report->reference ?? 'No reference provided.' }}</dd></div><div><dt class="tb-muted">Reporting</dt><dd class="mt-1 break-words">{{ $report->reporter->name }} · {{ $report->reported_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }} (Asia/Colombo)</dd></div></dl>
                @if ($report->note !== null)<p class="whitespace-pre-wrap break-words leading-7">{{ $report->note }}</p>@endif
                @if ($receipt = $report->acknowledgment)
                    <p class="break-words font-medium">Receipt acknowledged by {{ $receipt->acknowledger->name }} · {{ $receipt->acknowledged_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }} (Asia/Colombo)</p>
                    @if ($receipt->note !== null)<p class="whitespace-pre-wrap break-words leading-7">{{ $receipt->note }}</p>@endif
                @else<p class="tb-muted">Receipt awaiting Freelancer acknowledgment.</p>@endif
            @else<p class="tb-muted">Pending — no Client payment report.</p>@endif
        </div>
    </x-dashboard.panel>
    <x-dashboard.panel title="Current deliverable versions and reviews">
        @if ($deliverables->isEmpty())
            <x-dashboard.empty-state icon="document-text" title="No deliverables on this page." description="Submitted work and version reviews for this assignment will appear here." />
        @else
            <ol class="space-y-5 p-5 sm:p-6" aria-label="Current assignment deliverables">
                @foreach ($deliverables as $deliverable)
                    <li class="space-y-4 rounded-xl border border-slate-200 p-5 dark:border-slate-700" wire:key="case-deliverable-{{ $deliverable->id }}"><h3 class="break-words text-lg font-semibold">{{ $deliverable->title }}</h3><p class="tb-muted break-words">{{ $deliverable->milestone?->title ?? 'Not linked to a milestone' }}</p>@if ($deliverable->description)<p class="whitespace-pre-wrap break-words leading-7">{{ $deliverable->description }}</p>@endif
                        @forelse ($deliverable->versions as $version)
                            <x-deliverables.version :version="$version" :deliverable="$deliverable" :project="$project" :latest="$loop->first" :download-url="$adminContext ? route('admin.disputes.download', [$dispute, $version]) : route('projects.deliverables.download', [$project, $deliverable, $version])" />
                        @empty<p class="tb-muted">No submitted versions.</p>@endforelse
                    </li>
                @endforeach
            </ol>
        @endif
    </x-dashboard.panel>
    {{ $deliverables->links() }}
    @unless ($adminContext)
        <div class="flex flex-wrap gap-3"><flux:button :href="route('projects.plan', $project)" wire:navigate>Project plan</flux:button><flux:button :href="route('projects.payment', $project)" wire:navigate>External payment</flux:button></div>
    @endunless
</section>
