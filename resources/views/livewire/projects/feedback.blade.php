<?php

use App\Actions\Feedback\SubmitProjectRating;
use App\Models\Project;
use App\Models\ProjectRating;
use App\Support\ProjectFeedback;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app'), Title('Project feedback')] class extends Component {
    #[Locked]
    public int $projectId;

    #[Locked]
    public string $submissionId;

    public $rating = '';
    public $feedback = '';
    public $confirmed = false;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->submissionId = (string) Str::uuid();
        ProjectFeedback::context($this->projectId);
    }

    public function refreshFeedback(): void { ProjectFeedback::context($this->projectId); }

    public function submit(): void
    {
        $this->resetValidation();
        app(SubmitProjectRating::class)->submit($this->projectId, ['rating' => $this->rating, 'feedback' => $this->feedback, 'confirmed' => $this->confirmed], $this->submissionId);
        session()->flash('feedback-saved', 'Your feedback was submitted. Its score, comments and original submission time are now permanent.');
    }

    public function with(): array
    {
        [$user, $project, $assignment, $other] = ProjectFeedback::context($this->projectId);
        $window = ProjectFeedback::window($project);
        // Only own feedback and a query-filtered released counterpart can reach this view.
        $own = $assignment->ratings()->where('reviewer_id', $user->id)->first();
        $received = $assignment->ratings()->released()->where('reviewee_id', $user->id)
            ->with('reviewer:id,name')->first(['id', 'project_assignment_id', 'reviewer_id', 'reviewee_id', 'rating', 'feedback', 'submitted_at']);

        return ['project' => $project, 'assignment' => $assignment, 'otherName' => $other?->name ?? 'Project participant',
            'own' => $own, 'received' => $received, 'window' => $window,
            'canSubmit' => ! $own && $other && Gate::forUser($user)->allows('create', [ProjectRating::class, $assignment, $project, $other]),
            'returnUrl' => $user->isClient() ? route('client.projects.show', $project) : route('freelancer.assignments.show', $assignment)];
    }
}; ?>

<div class="tb-form-editor mx-auto max-w-4xl space-y-6" wire:poll.20s.visible="refreshFeedback">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0"><p class="tb-eyebrow mb-2">Private project workspace</p><h1 class="text-2xl font-bold sm:text-3xl">Project feedback</h1><p class="mt-2 break-words font-medium">{{ $project->title }}</p><p class="tb-muted mt-1 break-words">Your collaboration with {{ $otherName }}</p></div>
        <flux:button :href="$returnUrl" class="self-start shrink-0" wire:navigate>Back to project</flux:button>
    </div>
    <p role="status" aria-live="polite" aria-atomic="true" class="tb-editor-confirmation">{{ session('feedback-saved') }}</p>
    <flux:error name="feedback" />
    <flux:error name="submission_id" />

    <div class="tb-editor-notice space-y-3 rounded-xl border border-teal-200 p-5 dark:border-teal-900">
        <p>Feedback cannot be edited after submission. You can always read your own feedback. The other participant's feedback becomes shared only after both of you submit or the 14-day window ends.</p>
        @if ($window['start'])
            <p>Review period: <time datetime="{{ $window['start']->toIso8601String() }}">{{ $window['start']->setTimezone('Asia/Colombo')->format('j F Y, g:i:s a') }}</time> to <time datetime="{{ $window['end']->toIso8601String() }}">{{ $window['end']->setTimezone('Asia/Colombo')->format('j F Y, g:i:s a') }}</time> (Asia/Colombo). Submit before the closing time.</p>
            <p class="font-medium">{{ $window['ended'] ? 'The feedback window is closed. No new feedback can be submitted.' : ($window['open'] ? 'The feedback window is open.' : 'The feedback window has not started yet.') }}</p>
        @else
            <p class="font-medium">Feedback is unavailable until the project is explicitly completed with its original completion time and actor. Missing historical completion metadata is not estimated.</p>
        @endif
        <p class="tb-muted text-sm">Payment reports, receipt acknowledgment and dispute status do not control this feedback period.</p>
    </div>

    <x-dashboard.panel title="Your submitted feedback">
        @if ($own)
            <div class="space-y-3 p-5 sm:p-6"><p class="font-semibold">Your rating: {{ $own->rating }} / 5</p><p class="whitespace-pre-wrap break-words leading-7">{{ $own->feedback ?? 'No written feedback.' }}</p><p class="tb-muted text-sm">Submitted <time datetime="{{ $own->submitted_at->toIso8601String() }}">{{ $own->submitted_at->setTimezone('Asia/Colombo')->format('j F Y, g:i:s a') }}</time> (Asia/Colombo).</p></div>
        @elseif ($canSubmit)
            <form wire:submit="submit" class="space-y-6 p-5 sm:p-6" aria-label="Submit project feedback">
                <fieldset><legend class="mb-3 font-semibold">Your rating for {{ $otherName }} (required)</legend><div class="flex flex-wrap gap-3">
                    @foreach ([1 => 'Very poor', 2 => 'Poor', 3 => 'Satisfactory', 4 => 'Good', 5 => 'Excellent'] as $score => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-slate-50 px-3 py-3 text-base focus-within:ring-2 focus-within:ring-teal-600 dark:border-slate-600 dark:bg-slate-800 dark:focus-within:ring-teal-300"><input type="radio" name="project-rating" wire:model="rating" value="{{ $score }}" aria-label="{{ $score }} out of 5, {{ $label }}" @error('rating') aria-invalid="true" aria-describedby="project-rating-error" @enderror required class="size-4 accent-teal-700 dark:accent-teal-300" /><span>{{ $score }} <span class="tb-muted">{{ $label }}</span></span></label>
                    @endforeach
                </div><div id="project-rating-error"><flux:error name="rating" /></div></fieldset>
                <flux:textarea wire:model="feedback" label="Written feedback (optional)" description="Plain text, up to 2,000 characters. Describe your experience of this collaboration." rows="5" maxlength="2000" />
                <flux:checkbox wire:model="confirmed" label="I understand that this feedback is permanent and becomes shared after both participants submit or the review window ends." />
                <flux:error name="confirmed" />
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="submit">Submit permanent feedback</flux:button>
                <p role="status" aria-live="polite" wire:loading wire:target="submit">Submitting feedback...</p>
            </form>
        @else
            <p class="tb-muted p-5 leading-7 sm:p-6">You have not submitted feedback. {{ $window['ended'] ? 'The submission window has closed.' : 'Feedback can be submitted only during the review period by the original project participants in their Client and Freelancer roles.' }}</p>
        @endif
    </x-dashboard.panel>

    <x-dashboard.panel title="Feedback received">
        @if ($received)
            <div class="space-y-3 p-5 sm:p-6"><p class="font-semibold">Released feedback · {{ $received->rating }} / 5</p><p class="break-words">By {{ $received->reviewer->name }}</p><p class="whitespace-pre-wrap break-words leading-7">{{ $received->feedback ?? 'No written feedback.' }}</p><p class="tb-muted text-sm">Submitted <time datetime="{{ $received->submitted_at->toIso8601String() }}">{{ $received->submitted_at->setTimezone('Asia/Colombo')->format('j F Y, g:i:s a') }}</time> (Asia/Colombo).</p></div>
        @elseif ($window['ended'])
            <p class="tb-muted p-5 leading-7 sm:p-6">No feedback was received from the other participant during the review period.</p>
        @else
            <p class="tb-muted p-5 leading-7 sm:p-6">Awaiting shared feedback. Counterparty scores and comments remain hidden until both participants submit or the review window ends.</p>
        @endif
    </x-dashboard.panel>
</div>
