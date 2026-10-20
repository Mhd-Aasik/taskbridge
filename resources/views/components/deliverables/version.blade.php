@props(['version', 'project', 'deliverable', 'latest' => false])

<div {{ $attributes->class('space-y-4') }}>
    <div class="flex flex-wrap items-center gap-3">
        <h3 class="font-semibold">Version {{ $version->version_number }}</h3>
        @if ($latest)
            <span class="rounded-full border border-teal-300 px-2.5 py-1 text-sm font-medium text-teal-900 dark:border-teal-700 dark:text-teal-200">Latest version</span>
        @endif
    </div>
    <dl class="grid gap-4 sm:grid-cols-2">
        <div class="min-w-0"><dt class="tb-muted text-sm">File</dt><dd class="mt-1 break-words font-medium">{{ $version->original_filename }}</dd></div>
        <div><dt class="tb-muted text-sm">File size</dt><dd class="mt-1 tabular-nums">{{ $version->byte_size >= 1048576 ? number_format($version->byte_size / 1048576, 2).' MiB' : ($version->byte_size >= 1024 ? number_format($version->byte_size / 1024, 1).' KiB' : number_format($version->byte_size).' bytes') }}</dd></div>
        <div class="min-w-0"><dt class="tb-muted text-sm">Submitted by</dt><dd class="mt-1 break-words">{{ $version->uploader->name }}</dd></div>
        <div><dt class="tb-muted text-sm">Submitted (Sri Lanka)</dt><dd class="mt-1"><time datetime="{{ $version->submitted_at->toIso8601String() }}">{{ $version->submitted_at->setTimezone('Asia/Colombo')->format('j F Y, g:i a') }}</time></dd></div>
    </dl>
    @if ($version->submission_note !== null && $version->submission_note !== '')
        <div><p class="tb-muted text-sm">Submission note</p><p class="mt-1 whitespace-pre-wrap break-words leading-7">{{ $version->submission_note }}</p></div>
    @endif
    <flux:button :href="route('projects.deliverables.download', [$project, $deliverable, $version])" icon="arrow-down-tray" :aria-label="'Download version '.$version->version_number.' of '.$deliverable->title">Download version {{ $version->version_number }}</flux:button>
</div>
