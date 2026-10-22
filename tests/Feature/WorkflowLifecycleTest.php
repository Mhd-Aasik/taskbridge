<?php

namespace Tests\Feature;

use App\Enums\ProjectActivityEvent;
use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\InAppNotification;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectRating;
use App\Models\Skill;
use App\Models\User;
use App\Support\AccountAccess;
use App\Support\TaskProgress;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WorkflowLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $client;

    protected User $freelancer;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 04:30:00', 'UTC'));
        foreach (['local', 'avatars', 'project_covers', 'deliverables'] as $disk) {
            Storage::fake($disk);
        }
        config(['livewire.temporary_file_upload.cleanup' => false]);
        Mail::fake();
        Notification::fake();
        // Only accounts and reference data use factories; business records use real entry points.
        $this->client = User::factory()->create(['role' => 'client', 'name' => 'Audit Client']);
        $this->freelancer = User::factory()->create(['role' => 'freelancer', 'name' => 'Audit Freelancer']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->category = Category::factory()->create();
    }

    public function test_participants_complete_the_full_collaboration_through_http_and_volt_preserving_terms_and_history(): void
    {
        [$project, $proposal, $assignment] = $this->assignedWorkflow();
        $agreed = $assignment->fresh()->getAttributes();
        $offer = Arr::only($proposal->fresh()->getAttributes(), ['project_id', 'freelancer_id', 'cover_letter', 'proposed_budget', 'proposed_deadline', 'submitted_at']);
        $coverHash = hash('sha256', Storage::disk('project_covers')->get($project->cover_path));
        $publication = $project->fresh()->getRawOriginal('published_at');

        $plan = Volt::test('projects.plan', ['project' => $project])->set('form.title', 'Delivery milestone')->set('form.description', 'A plain-text outcome')
            ->set('form.due_date', '2026-11-12')->call('save')->assertHasNoErrors();
        $milestone = $assignment->milestones()->sole();
        $plan->set('taskForm.title', 'Prepare the deliverable')->set('taskForm.description', 'Task progress is separate from approval.')
            ->set('taskForm.due_date', '2026-11-10')->set('taskForm.milestone_id', (string) $milestone->id)->call('saveTask')->assertHasNoErrors();
        $task = $assignment->tasks()->sole();
        $plan->set('taskStatusFilter', 'done')->assertViewHas('taskProgress', ['total' => 1, 'completed' => 0, 'percentage' => 0]);

        $this->login($this->freelancer);
        Volt::test('projects.plan', ['project' => $project])->call('changeTaskStatus', $task->id, 'done', $task->status_version)->assertHasNoErrors();
        $this->assertSame(['total' => 1, 'completed' => 1, 'percentage' => 100], TaskProgress::forAssignment($assignment));
        Volt::test('projects.plan', ['project' => $project])->call('save')->assertForbidden();
        $first = $this->upload($project, $milestone);
        $firstBytes = Storage::disk('deliverables')->get($first->storage_path);

        $this->login($this->client);
        $review = Volt::test('projects.deliverables.show', ['project' => $project, 'deliverable' => $first->deliverable])
            ->set('reviewRemarks', 'Please clarify the result.')->call('requestChanges')->assertHasNoErrors();
        $firstReview = $first->review()->sole();
        $firstReviewSnapshot = $firstReview->getAttributes();
        $this->assertSame('changes_requested', $firstReview->decision->value);

        $this->login($this->freelancer);
        Volt::test('projects.deliverables.show', ['project' => $project, 'deliverable' => $first->deliverable])
            ->set('submission_note', 'Requested clarification included.')->set('file', UploadedFile::fake()->createWithContent('revision.txt', 'The revised immutable work.'))
            ->call('save')->assertHasNoErrors();
        $second = $first->deliverable->versions()->where('version_number', 2)->sole();
        $staleUpload = Volt::test('projects.deliverables.show', ['project' => $project, 'deliverable' => $first->deliverable])
            ->set('file', UploadedFile::fake()->createWithContent('stale.txt', 'A stale version must not be saved.'));

        $this->login($this->client);
        $review->set('reviewConfirmed', true)->call('approveVersion')->assertHasErrors(['review']);
        $approval = Volt::test('projects.deliverables.show', ['project' => $project, 'deliverable' => $first->deliverable])
            ->set('reviewConfirmed', true)->call('approveVersion')->assertHasNoErrors();
        $approval->call('approveVersion')->assertHasNoErrors();
        $immutableVersions = DeliverableVersion::orderBy('id')->get()->toJson();
        $immutableReviews = DeliverableReview::orderBy('id')->get()->toJson();
        $this->assertSame($firstReviewSnapshot, $firstReview->fresh()->getAttributes());
        $this->assertSame($firstBytes, Storage::disk('deliverables')->get($first->storage_path));

        $this->login($this->freelancer);
        $staleUpload->call('save')->assertHasErrors(['deliverables']);
        $this->assertDatabaseCount('deliverable_versions', 2);
        $this->login($this->client);
        Volt::test('projects.plan', ['project' => $project])->set('milestoneConfirmed.'.$milestone->id, true)
            ->call('completeMilestone', $milestone->id)->assertHasNoErrors();
        $completion = Volt::test('projects.completion', ['project' => $project])->set('confirmed', true)->call('complete')->assertHasNoErrors();
        $completedAt = $project->fresh()->getRawOriginal('completed_at');
        $completion->call('complete')->assertHasNoErrors();
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);

        $payment = Volt::test('projects.payment', ['project' => $project])->set('reportConfirmed', true)->set('reportReference', 'PRIVATE-BANK-REFERENCE')
            ->call('recordPayment')->assertHasNoErrors();
        $payment->call('recordPayment')->assertHasNoErrors();
        $report = $assignment->paymentReport()->sole();
        $this->assertSame('175000.25', $report->amount);
        $this->login($this->freelancer);
        $receipt = Volt::test('projects.payment', ['project' => $project])->set('receiptConfirmed', true)->call('acknowledgeReceipt')->assertHasNoErrors();
        $receipt->call('acknowledgeReceipt')->assertHasNoErrors();
        $this->assertSame(1, $report->acknowledgment()->count());

        Volt::test('messages.show', ['project' => $project])->set('body', "Private completed-project message\n<script>unsafe</script>")
            ->call('sendMessage')->assertHasNoErrors();
        $message = $assignment->messages()->sole();
        $alert = $this->client->notifications()->where('type', 'project_message_sent')->sole();
        $this->login($this->client);
        $this->get(route('notifications.index'))->assertOk()->assertDontSeeText('Private completed-project message');
        Volt::test('notifications.index')->call('openNotification', $alert->id)->assertRedirect(route('projects.messages', $project));
        $this->assertNotNull($alert->fresh()->read_at);
        $this->assertDatabaseCount('project_read_cursors', 0);
        $conversation = Volt::test('messages.show', ['project' => $project])->assertViewHas('unread', 1)->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false);
        $conversation->call('markDisplayedRead', $conversation->viewData('presentation'))->assertHasNoErrors()->assertViewHas('unread', 0);
        $this->assertSame($message->id, $assignment->readCursors()->sole()->last_read_message_id);

        Volt::test('projects.feedback', ['project' => $project])->set('rating', 4)->set('feedback', 'CLIENT_BLIND_COMMENT')->set('confirmed', true)->call('submit')->assertHasNoErrors();
        $this->login($this->freelancer);
        Volt::test('projects.feedback', ['project' => $project])->assertViewHas('received', null)->assertDontSeeText('CLIENT_BLIND_COMMENT')
            ->set('rating', 5)->set('feedback', 'FREELANCER_SHARED_COMMENT')->set('confirmed', true)->call('submit')->assertHasNoErrors()->assertSeeText('CLIENT_BLIND_COMMENT');
        $this->assertSame(2, ProjectRating::query()->released()->count());
        $this->get(route('projects.deliverables.download', [$project, $first->deliverable_id, $first]))->assertOk();
        $this->get(route('projects.deliverables.download', [$project, $second->deliverable_id, $second]))->assertOk();
        $this->get(route('projects.activity', $project))->assertOk()->assertDontSeeText('Project published')->assertDontSeeText('PRIVATE-BANK-REFERENCE')->assertDontSeeText('CLIENT_BLIND_COMMENT');
        $this->assertNavigationLinksWork();

        $this->login($this->client);
        $this->get(route('projects.feedback', $project))->assertOk()->assertSeeText('FREELANCER_SHARED_COMMENT');
        $this->get(route('client.dashboard'))->assertOk()->assertViewHas('completedProjectCount', 1)->assertViewHas('openCount', 0)->assertViewHas('receivedProposalCount', 1);
        $this->get(route('projects.activity', $project))->assertOk()->assertSeeText('Project published')->assertSeeText('Project completed')->assertDontSeeText('PRIVATE-BANK-REFERENCE');
        $this->assertNavigationLinksWork();
        $this->assertSame($agreed, $assignment->fresh()->getAttributes());
        $this->assertSame($offer, Arr::only($proposal->fresh()->getAttributes(), array_keys($offer)));
        $this->assertSame($publication, $project->fresh()->getRawOriginal('published_at'));
        $this->assertSame($completedAt, $project->fresh()->getRawOriginal('completed_at'));
        $this->assertSame($immutableVersions, DeliverableVersion::orderBy('id')->get()->toJson());
        $this->assertSame($immutableReviews, DeliverableReview::orderBy('id')->get()->toJson());
        $this->assertSame($coverHash, hash('sha256', Storage::disk('project_covers')->get($project->cover_path)));
        $this->assertDatabaseCount('project_activities', 13);
        $this->login($this->admin);
        $this->get(route('projects.activity', $project))->assertForbidden();
        $this->get(route('projects.deliverables.download', [$project, $first->deliverable_id, $first]))->assertForbidden();
        $this->assertNavigationLinksWork();
    }

    public function test_interruptions_preserve_assigned_work_and_atomic_history_across_deadlines_disputes_and_suspension(): void
    {
        // A real saved draft crosses Sri Lankan midnight without changing any production dates.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 18:25:00', 'UTC'));
        $this->login($this->client);
        $expiredDraft = $this->draft('2026-11-04');
        $this->travel(5)->minutes();
        $this->post(route('client.projects.publish', $expiredDraft), ['confirmed' => true])->assertSessionHasErrorsIn('publication', ['deadline']);
        $this->assertSame(ProjectStatus::Draft, $expiredDraft->fresh()->status);
        $this->assertDatabaseCount('project_activities', 0);
        [$project, , $assignment] = $this->assignedWorkflow();
        $originalTerms = $assignment->fresh()->getAttributes();

        $this->login($this->admin);
        Volt::test('admin.categories')->call('prepareActivation', $this->category->id, false)->call('confirmActivation')->assertHasNoErrors();
        $this->assertFalse($this->category->fresh()->is_active);
        $this->login($this->freelancer);
        $version = $this->upload($project);
        $this->login($this->client);
        Volt::test('projects.deliverables.show', ['project' => $project, 'deliverable' => $version->deliverable])
            ->set('reviewConfirmed', true)->call('approveVersion')->assertHasNoErrors();
        Volt::test('disputes.create', ['project' => $project])->set('title', 'Clarify the approved result')->set('reason', 'Please review the agreed interpretation.')
            ->set('confirmed', true)->call('openDispute')->assertHasNoErrors();
        $case = DisputeCase::sole();
        Volt::test('projects.completion', ['project' => $project])->set('confirmed', true)->call('complete')->assertHasErrors(['completion']);
        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
        $this->get(route('projects.deliverables.download', [$project, $version->deliverable_id, $version]))->assertOk();

        $staleFreelancer = $this->freelancer->fresh();
        $this->login($this->admin);
        Volt::test('admin.accounts.show', ['account' => $this->freelancer->id])->call('prepareStatus')->set('reason', 'INTERNAL-STATUS-REASON')
            ->call('confirmStatus')->assertHasNoErrors();
        $this->actingAs($staleFreelancer)->withSession([AccountAccess::SESSION_KEY => ['id' => $staleFreelancer->id, 'version' => 0]])
            ->get(route('freelancer.dashboard'))->assertRedirectToRoute('login');
        Volt::test('auth.login')->set('email', $this->freelancer->email)->set('password', 'password')->call('login')->assertHasErrors(['email']);
        $this->login($this->client);
        foreach (['projects.plan', 'projects.activity', 'projects.payment', 'projects.messages'] as $route) {
            $this->get(route($route, $project))->assertOk()->assertDontSeeText('INTERNAL-STATUS-REASON');
        }
        $this->get(route('projects.deliverables.download', [$project, $version->deliverable_id, $version]))->assertOk();
        $this->login($this->admin);
        Volt::test('admin.accounts.show', ['account' => $this->freelancer->id])->call('prepareStatus')->set('reason', 'Review complete; restore access.')
            ->call('confirmStatus')->assertHasNoErrors();
        $this->actingAs($staleFreelancer)->withSession([AccountAccess::SESSION_KEY => ['id' => $staleFreelancer->id, 'version' => 0]])
            ->get(route('freelancer.dashboard'))->assertRedirectToRoute('login');
        $this->login($this->freelancer);
        $this->get(route('freelancer.proposals.show', $assignment->proposal_id))->assertOk();

        // Fail real upload entry points after file creation; both auxiliary histories must roll back.
        foreach ([InAppNotification::class, ProjectActivity::class] as $model) {
            $tables = ['deliverables', 'deliverable_versions', 'notifications', 'project_activities'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
            $files = Storage::disk('deliverables')->allFiles();
            $event = 'eloquent.creating: '.$model;
            Event::listen($event, fn () => false);
            try {
                Volt::test('projects.deliverables.index', ['project' => $project])->set('title', 'Must roll back')->set('file', UploadedFile::fake()->createWithContent('failed.txt', 'Do not persist this failed submission.'))
                    ->call('save')->assertHasErrors(['deliverables']);
            } finally {
                Event::forget($event);
            }
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
            }
            $this->assertSame($files, Storage::disk('deliverables')->allFiles());
        }

        $this->login($this->admin);
        $this->get(route('projects.activity', $project))->assertForbidden();
        $resolution = Volt::test('disputes.show', ['dispute' => $case, 'administration' => true])->set('rationale', 'ADMIN-CASE-RATIONALE')
            ->set('resolutionConfirmed', true)->call('resolveCase')->assertHasNoErrors();
        $resolution->call('resolveCase')->assertHasNoErrors();
        $this->login($this->client);
        Volt::test('projects.completion', ['project' => $project])->set('confirmed', true)->call('complete')->assertHasNoErrors();
        $this->get(route('projects.activity', $project))->assertOk()->assertSeeText('Dispute resolved')->assertDontSeeText('ADMIN-CASE-RATIONALE');
        $this->login($this->freelancer);
        $this->get(route('projects.plan', $project))->assertOk()->assertSeeText('read-only');
        $this->get(route('projects.deliverables.download', [$project, $version->deliverable_id, $version]))->assertOk();
        $this->assertSame($originalTerms, $assignment->fresh()->getAttributes());
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
        $this->assertFalse($this->category->fresh()->is_active);
    }

    public function test_profile_publication_and_photo_revocation_control_directory_access_without_erasing_assignment_history(): void
    {
        [$project, , $assignment] = $this->assignedWorkflow();
        $this->login($this->freelancer);
        Volt::test('settings.photo')->set('photo', UploadedFile::fake()->image('account.png', 60, 60))->call('savePhoto')->assertHasNoErrors();
        $photoPath = $this->freelancer->fresh()->avatar_path;
        $photoBytes = Storage::disk('avatars')->get($photoPath);
        $skill = Skill::factory()->create();
        Volt::test('freelancer.profile')->set('headline', 'Audit profile headline')->set('bio', "Professional plain-text introduction\n<script>profile</script>")
            ->set('location', 'Colombo')->set('hourly_rate', '2500.00')->set('skill_ids', [$skill->id])->call('save')->assertHasNoErrors();
        $profile = $this->freelancer->freelancerProfile()->sole();
        $this->get(route('freelancer.profile.preview'))->assertOk()->assertSee('&lt;script&gt;profile&lt;/script&gt;', false);
        $this->login($this->client);
        $this->get(route('client.freelancers.show', $profile))->assertNotFound();
        $this->login($this->freelancer);
        Volt::test('freelancer.profile')->call('publish')->assertHasNoErrors();
        $firstPublished = $profile->fresh()->getRawOriginal('first_published_at');
        $this->login($this->client);
        $this->get(route('client.freelancers.index', ['search' => 'Audit profile']))->assertOk()->assertSeeText('Audit profile headline');
        $this->get(route('client.freelancers.show', $profile))->assertOk()->assertDontSee($this->freelancer->email)->assertDontSee($photoPath);
        $photoUrl = route('client.freelancers.photo', $profile);
        $this->get($photoUrl)->assertNotFound();
        $this->get(route('account.photo', $this->freelancer))->assertForbidden();
        $this->login($this->freelancer);
        Volt::test('freelancer.profile')->set('shareAccountPhoto', true)->call('savePhotoPreference')->assertHasNoErrors();
        $this->login($this->client);
        $this->get($photoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->login($this->freelancer);
        Volt::test('freelancer.profile')->set('shareAccountPhoto', false)->call('savePhotoPreference')->assertHasNoErrors();
        $this->login($this->client);
        $this->get($photoUrl)->assertNotFound();
        $this->get(route('client.freelancers.show', $profile))->assertOk();
        $this->login($this->freelancer);
        Volt::test('freelancer.profile')->call('unpublish')->assertHasNoErrors();
        $this->get(route('account.photo', $this->freelancer))->assertOk();
        $this->get(route('freelancer.assignments.show', $assignment))->assertOk();
        $this->login($this->client);
        $this->get(route('client.freelancers.show', $profile))->assertNotFound();
        $this->get(route('client.freelancers.index', ['search' => 'Audit profile']))->assertOk()->assertDontSeeText('Audit profile headline');
        $this->get(route('client.projects.proposals.show', [$project, $assignment->proposal_id]))->assertOk()->assertDontSeeText('View profile');
        $this->get(route('client.projects.show', $project))->assertOk();
        $this->assertSame($firstPublished, $profile->fresh()->getRawOriginal('first_published_at'));
        $this->assertSame($photoBytes, Storage::disk('avatars')->get($photoPath));
        $this->assertModelExists($assignment);
    }

    private function login(User $user): void
    {
        if (Auth::check()) {
            $this->post(route('logout'))->assertRedirect('/');
        }
        $this->get(route('login'))->assertOk();
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->get(route('dashboard'))->assertRedirectToRoute($user->role.'.dashboard');
    }

    private function draft(string $deadline = '2026-11-20'): Project
    {
        $this->get(route('client.projects.create'))->assertOk();
        $before = Project::count();
        Volt::test('client.projects.editor')->set('form.title', 'Audit collaboration')->set('form.description', "Saved plain-text brief\n<script>brief</script>")
            ->set('form.category_id', (string) $this->category->id)->set('form.budget', '200000.50')->set('form.deadline', $deadline)
            ->set('cover', UploadedFile::fake()->image('cover.png', 240, 120))->call('save')->assertHasNoErrors();
        $this->assertSame($before + 1, Project::count());

        return $this->client->projects()->latest('id')->firstOrFail();
    }

    private function assignedWorkflow(): array
    {
        $this->login($this->client);
        $project = $this->draft();
        $staleEditor = Volt::test('client.projects.editor', ['project' => $project]);
        $this->get(route('client.projects.show', $project))->assertOk()->assertSee('&lt;script&gt;brief&lt;/script&gt;', false);
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        $this->post(route('client.projects.publish', $project), ['confirmed' => true, '_token' => csrf_token()])->assertSessionHasNoErrors();
        $staleEditor->call('save')->assertForbidden();
        $this->get(route('freelancer.projects.index'))->assertForbidden();
        $this->login($this->freelancer);
        $this->get(route('client.projects.show', $project))->assertForbidden();
        $this->get(route('freelancer.projects.index', ['search' => 'Audit collaboration', 'min_budget' => '100000.00', 'sort' => 'budget_desc']))->assertOk()->assertSeeText($project->title);
        $this->get(route('freelancer.projects.show', $project))->assertOk()->assertDontSee($this->client->email);
        $submission = Volt::test('freelancer.proposals.create', ['project' => $project])->set('form.cover_letter', 'I can deliver this clearly defined result.')
            ->set('form.proposed_budget', '175000.25')->set('form.proposed_deadline', '2026-11-15')->call('submit')->assertHasNoErrors();
        $proposal = $this->freelancer->submittedProposals()->sole();
        $snapshot = $proposal->fresh()->getAttributes();
        $submission->call('submit')->assertHasNoErrors();
        $this->assertSame($snapshot, $proposal->fresh()->getAttributes());
        $this->login($this->client);
        $this->get(route('client.projects.proposals.index', $project))->assertOk()->assertSeeText($this->freelancer->name);
        $this->get(route('client.projects.proposals.show', [$project, $proposal]))->assertOk();
        $acceptUrl = route('client.projects.proposals.accept', [$project, $proposal]);
        $this->post($acceptUrl, ['confirmed' => true, '_token' => csrf_token()])->assertSessionHasNoErrors();
        $assignment = $project->assignment()->sole();
        $original = $assignment->fresh()->getAttributes();
        $this->post($acceptUrl, ['confirmed' => true, '_token' => csrf_token()])->assertSessionHasNoErrors();
        $this->assertSame($original, $assignment->fresh()->getAttributes());
        $this->assertSame('175000.25', $assignment->agreed_budget);
        $this->assertSame('2026-11-15', $assignment->agreed_deadline->toDateString());
        $this->assertSame(1, ProjectActivity::where('event_type', ProjectActivityEvent::Assigned)->count());

        return [$project->fresh(), $proposal->fresh(), $assignment->fresh()];
    }

    private function upload(Project $project, ?Milestone $milestone = null): DeliverableVersion
    {
        Volt::test('projects.deliverables.index', ['project' => $project])->set('title', 'Private outcome')->set('milestone_id', $milestone ? (string) $milestone->id : '')
            ->set('file', UploadedFile::fake()->createWithContent('work.txt', 'The original immutable work.'))->call('save')->assertHasNoErrors();

        return DeliverableVersion::latest('id')->firstOrFail();
    }

    private function assertNavigationLinksWork(): void
    {
        $response = $this->get(route('dashboard'))->assertRedirect();
        $html = $this->get($response->headers->get('Location'))->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $urls = [];
        foreach ($xpath->query('//aside//a[@href] | //header//a[@href] | //nav//a[@href]') as $link) {
            $url = $link->getAttribute('href');
            if (str_starts_with($url, url('/').'/') && ! str_contains($url, '#')) {
                $urls[$url] = true;
            }
        }
        $this->assertNotEmpty($urls);
        foreach (array_keys($urls) as $url) {
            $response = $this->get($url);
            $this->assertContains($response->status(), [200, 302], $url);
        }
    }
}
