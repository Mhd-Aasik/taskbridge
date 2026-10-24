<?php

namespace App\Support;

use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Actions\Disputes\OpenDispute;
use App\Actions\Feedback\SubmitProjectRating;
use App\Actions\Messages\SendProjectMessage;
use App\Actions\Milestones\CompleteMilestone;
use App\Actions\Milestones\SaveMilestone;
use App\Actions\Payments\AcknowledgePayment;
use App\Actions\Payments\ReportPayment;
use App\Actions\Profiles\ChangeProfileVisibility;
use App\Actions\Projects\CompleteProject;
use App\Actions\Projects\PublishProject;
use App\Actions\Projects\SaveProjectDraft;
use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Actions\Tasks\ChangeProjectTaskStatus;
use App\Actions\Tasks\SaveProjectTask;
use App\Models\Category;
use App\Models\DeliverableVersion;
use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit, local-only fixture orchestration; never called by normal seeders. */
class LocalDemonstration
{
    public static function ensureLocal(): void
    {
        if (! app()->runningInConsole() || ! app()->environment('local')) {
            throw new RuntimeException('Demonstration setup is console-only and requires APP_ENV=local.');
        }
        if (! in_array(config('mail.default'), ['log', 'array'], true)) {
            throw new RuntimeException('Demonstration setup requires log or array mail; external delivery is forbidden.');
        }
    }

    public function run(int $clientId, int $freelancerId, ?string $manifestPath = null): array
    {
        self::ensureLocal();
        $client = User::findOrFail($clientId);
        $freelancer = User::findOrFail($freelancerId);
        if (! $client->isClient() || ! $freelancer->isFreelancer() || ! $client->isActive() || ! $freelancer->isActive()) {
            throw new RuntimeException('Select distinct active Client and Freelancer accounts.');
        }
        $path = $manifestPath ?? storage_path('app/taskbridge-demo-v1.json');
        $lock = fopen($path.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another local demonstration setup is running.');
        }
        $previousActor = Auth::user();
        try {
            if (is_file($path)) {
                $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (($manifest['version'] ?? null) !== 1 || $manifest['client_id'] !== $clientId || $manifest['freelancer_id'] !== $freelancerId) {
                    throw new RuntimeException('Existing demonstration manifest belongs to different accounts or a different version.');
                }
                $matching = 0;
                foreach ($manifest['projects'] as $record) {
                    $project = Project::find($record['id']);
                    if ($project && ($project->client_id !== $clientId || $project->title !== $record['title'])) {
                        throw new RuntimeException('Managed project identity changed; refusing to overwrite history.');
                    }
                    $matching += $project !== null ? 1 : 0;
                }
                if ($matching === 8) {
                    return $manifest; // Never reset dates, history, files or subsequent user edits.
                }
                if ($matching !== 0 || $manifest['phase'] !== 'prepared') {
                    throw new RuntimeException('Incomplete or missing managed history requires operator review.');
                }
                // A prepared manifest with no rows means the enclosing transaction rolled back.
            }
            $manifest = DB::transaction(function () use ($client, $freelancer, $path): array {
                $today = CarbonImmutable::today('Asia/Colombo');
                $manifest = ['version' => 1, 'phase' => 'prepared', 'client_id' => $client->id, 'freelancer_id' => $freelancer->id, 'created_on' => $today->toDateString(), 'projects' => []];
                $this->profile($freelancer);
                foreach (require resource_path('demo/projects.php') as $entry) {
                    $this->actor($client);
                    $category = Category::where('slug', $entry['category'])->where('is_active', true)->firstOrFail();
                    $cover = new UploadedFile(resource_path('demo/covers/'.$entry['key'].'.webp'), $entry['key'].'.webp', 'image/webp', null, true);
                    $project = app(SaveProjectDraft::class)->save(null, [
                        'title' => $entry['title'], 'description' => "LOCAL DEMONSTRATION PROJECT\nNo real commission or payment is represented.\n\n".$entry['scope'],
                        'category_id' => $category->id, 'budget' => $entry['budget'], 'deadline' => $today->addDays($entry['days'])->toDateString(),
                    ], $cover);
                    if ($entry['state'] !== 'draft') {
                        app(PublishProject::class)->publish($client, $project->id, true);
                    }
                    if (in_array($entry['state'], ['in_progress', 'completed'], true)) {
                        $this->assignment($project, $client, $freelancer, $entry, $today);
                    } elseif ($entry['key'] === 'retailer') {
                        $this->actor($freelancer);
                        app(SubmitProposal::class)->submit($project->id, ['cover_letter' => 'I would deliver a mobile-first catalogue, accessible filtering and a clear enquiry handover. This is a demonstration offer, with no real commercial commitment.', 'proposed_budget' => '305000.00', 'proposed_deadline' => $today->addDays(48)->toDateString()]);
                    }
                    $manifest['projects'][] = ['id' => $project->id, 'key' => $entry['key'], 'title' => $entry['title']];
                }
                // Persist prepared identities before commit: a crash cannot leave untracked committed rows.
                $this->writeManifest($path, $manifest);

                return $manifest;
            });
            $manifest['phase'] = 'complete';
            $this->writeManifest($path, $manifest);

            return $manifest;
        } finally {
            Auth::guard()->setUser($previousActor ?? $client);
            if ($previousActor === null) {
                Auth::forgetGuards();
            }
            request()->attributes->remove('taskbridge.available_actor');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeManifest(string $path, array $manifest): void
    {
        if (file_put_contents($path.'.pending', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false || ! rename($path.'.pending', $path)) {
            throw new RuntimeException('The local demonstration manifest could not be saved.');
        }
    }

    private function actor(User $user): void
    {
        Auth::setUser($user->fresh());
        request()->attributes->remove('taskbridge.available_actor');
    }

    private function profile(User $user): void
    {
        $this->actor($user);
        [$actor, $profile] = FreelancerProfiles::owned(lock: true);
        $skills = Skill::whereIn('slug', ['laravel', 'php', 'javascript', 'ui-ux-design'])->pluck('id')->all();
        if ($skills === []) {
            $skills = Skill::orderBy('id')->limit(3)->pluck('id')->all();
        }
        $input = FreelancerProfileRules::validate(['headline' => 'Web developer and thoughtful interface designer', 'bio' => 'I build clear, dependable websites and digital workspaces for small businesses. My approach combines Laravel development, responsive interface design and careful handover documentation. This local demonstration profile illustrates the TaskBridge workflow; its projects and reviews are demonstration content.', 'location' => 'Colombo, Sri Lanka', 'portfolio_url' => null, 'hourly_rate' => '3500.00', 'skill_ids' => $skills]);
        $profile ??= new FreelancerProfile;
        $profile->user()->associate($actor);
        $profile->fill(array_diff_key($input, ['skill_ids' => true]));
        $profile->saveOrFail();
        $profile->skills()->sync($skills);
        app(ChangeProfileVisibility::class)->publish(FreelancerProfiles::revision($profile->fresh()));
    }

    private function assignment(Project $project, User $client, User $freelancer, array $entry, CarbonImmutable $today): void
    {
        $this->actor($freelancer);
        $proposal = app(SubmitProposal::class)->submit($project->id, ['cover_letter' => 'I can deliver the agreed scope with regular written updates, accessible layouts and a documented handover. I will share a first preview, incorporate one review round and submit the final source. This is a local demonstration proposal.', 'proposed_budget' => $entry['budget'], 'proposed_deadline' => $today->addDays($entry['days'] - 2)->toDateString()])['proposal'];
        $this->actor($client);
        app(AcceptProposal::class)->accept($project->id, $proposal->id, true);
        $milestone = app(SaveMilestone::class)->save($project->id, null, ['title' => 'Design, implementation and handover', 'description' => 'Review the responsive preview, check the agreed acceptance criteria and approve the final demonstration handover.', 'due_date' => $today->addDays($entry['days'] - 3)->toDateString()]);
        $tasks = [];
        foreach (['Prepare responsive layouts', 'Implement and review the core experience', 'Document the final handover'] as $index => $title) {
            $tasks[] = app(SaveProjectTask::class)->save($project->id, null, ['title' => $title, 'description' => 'Demonstration task with explicit progress; task completion does not imply Client approval.', 'due_date' => $today->addDays(7 + $index * 4)->toDateString(), 'milestone_id' => $milestone->id]);
        }
        $this->actor($freelancer);
        foreach ($tasks as $index => $task) {
            if ($entry['state'] === 'completed' || $index < 2) {
                app(ChangeProjectTaskStatus::class)->change($project->id, $task->id, $entry['state'] === 'completed' || $index === 0 ? 'done' : 'in_progress', $task->status_version);
            }
        }
        app(SendProjectMessage::class)->send($project->id, ['body' => 'The first demonstration preview is ready. Please review the layout and acceptance checklist; the handover files are private to this project.'], (string) Str::uuid());
        $version = $this->deliverable($project, $milestone->id, null, 'Initial demonstration handover. The mobile navigation checklist will be expanded in the revision.', $entry['scope']);
        $this->actor($client);
        app(ReviewDeliverableVersion::class)->requestChanges($project->id, $version->deliverable_id, $version->id, ['remarks' => 'Please include the mobile navigation checklist and clarify the final handover steps.']);
        app(SendProjectMessage::class)->send($project->id, ['body' => 'Thank you. Please include the requested checklist in the next immutable version. This conversation is demonstration content.'], (string) Str::uuid());
        $this->actor($freelancer);
        $revision = $this->deliverable($project, $milestone->id, $version->deliverable_id, 'Revision includes the requested mobile navigation checklist and final handover steps.', $entry['scope']);
        if ($entry['state'] === 'completed') {
            $this->actor($client);
            app(ReviewDeliverableVersion::class)->approve($project->id, $revision->deliverable_id, $revision->id, ['confirmed' => true, 'remarks' => 'The demonstration handover meets the agreed acceptance checklist.']);
            app(CompleteMilestone::class)->complete($project->id, $milestone->id, ['confirmed' => true, 'completion_note' => 'Demonstration tasks and approved handover reviewed.']);
            app(CompleteProject::class)->complete($project->id, ['confirmed' => true, 'completion_note' => 'Local demonstration project completed through the normal approval workflow.']);
            $report = app(ReportPayment::class)->report($project->id, ['confirmed' => true, 'method' => 'bank_transfer', 'paid_on' => $today->toDateString(), 'reference' => null, 'note' => 'DEMONSTRATION ONLY: illustrates an external payment report; no money was transferred.'], (string) Str::uuid());
            app(SubmitProjectRating::class)->submit($project->id, ['confirmed' => true, 'rating' => 5, 'feedback' => 'Demonstration feedback: clear communication and a carefully documented handover.'], (string) Str::uuid());
            $this->actor($freelancer);
            if ($entry['key'] === 'reporting') {
                app(AcknowledgePayment::class)->acknowledge($project->id, $report->id, ['confirmed' => true, 'note' => 'DEMONSTRATION ONLY: illustrates acknowledgment; no funds were received.']);
            }
            app(SubmitProjectRating::class)->submit($project->id, ['confirmed' => true, 'rating' => 5, 'feedback' => 'Demonstration feedback: a clear brief, constructive review and an organised collaboration.'], (string) Str::uuid());
        } else {
            app(OpenDispute::class)->open($project->id, ['confirmed' => true, 'title' => 'Demonstration: clarify mobile acceptance criteria', 'reason' => 'This private demonstration case illustrates Admin review. Please clarify the remaining mobile-navigation acceptance checklist; it is not a real dispute or payment claim.'], (string) Str::uuid());
        }
    }

    private function deliverable(Project $project, int $milestoneId, ?int $deliverableId, string $note, string $scope): DeliverableVersion
    {
        $path = tempnam(sys_get_temp_dir(), 'tb-demo-');
        try {
            file_put_contents($path, "TASKBRIDGE LOCAL DEMONSTRATION HANDOVER\n\n".$scope."\n\n".$note."\n\nChecklist: keyboard navigation; 320px layout; labelled controls; clear validation; documented source and handover.\nNo live service, real commission or payment is represented.\n");

            return app(SubmitDeliverable::class)->submit($project->id, $deliverableId, ['title' => 'Responsive preview and handover checklist', 'description' => 'Private demonstration handover with an immutable review/revision history.', 'milestone_id' => $milestoneId, 'submission_note' => $note], new UploadedFile($path, 'demonstration-handover.txt', 'text/plain', null, true), (string) Str::uuid());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
