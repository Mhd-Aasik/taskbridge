<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\FreelancerProfile;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DeliverablePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
        Storage::fake('deliverables');
        Storage::fake('local');
    }

    public function test_guests_are_redirected_from_list_and_detail(): void
    {
        $assignment = $this->assignment();
        $deliverable = $this->deliverable($assignment);
        $this->get($this->indexUrl($assignment))->assertRedirect(route('login'));
        $this->get($this->showUrl($assignment, $deliverable))->assertRedirect(route('login'));
        $this->assertDatabaseCount('deliverable_versions', 0);
    }

    #[DataProvider('roles')]
    public function test_unrelated_accounts_and_admins_cannot_read_deliverables(string $role): void
    {
        $assignment = $this->assignment();
        $deliverable = $this->deliverable($assignment, ['title' => 'Private deliverable brief']);
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get($this->indexUrl($assignment))->assertForbidden()->assertDontSeeText($deliverable->title);
        $this->get($this->showUrl($assignment, $deliverable))->assertForbidden()->assertDontSeeText($deliverable->title);
        Volt::test('projects.deliverables.index', ['project' => $assignment->project])->assertForbidden();
    }

    #[DataProvider('participants')]
    public function test_participants_read_latest_and_complete_version_history_without_private_account_data(string $participant): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'title' => 'Design delivery']);
        $deliverable = $this->deliverable($assignment, ['milestone_id' => $milestone->id, 'title' => 'Brand concepts', 'description' => "Initial concepts\nSecond line"]);
        $first = $this->version($deliverable, ['original_filename' => 'first-concepts.txt', 'submission_note' => 'Original note', 'submitted_at' => '2026-10-22 18:30:00']);
        $latest = $this->version($deliverable, ['original_filename' => 'latest-concepts.txt', 'submission_note' => "Updated note\nAnother line", 'byte_size' => 2097152, 'submitted_at' => '2026-10-23 18:30:00']);
        FreelancerProfile::factory()->create(['user_id' => $assignment->freelancer_id, 'bio' => 'Private freelancer biography', 'is_public' => false]);
        $this->actingAs($this->participant($assignment, $participant));

        $this->get($this->indexUrl($assignment))->assertOk()->assertSeeText('Brand concepts')->assertSeeText('Design delivery')
            ->assertSeeText('latest-concepts.txt')->assertDontSeeText('first-concepts.txt')->assertSeeText('2 versions')
            ->assertSee($this->showUrl($assignment, $deliverable), false);
        $this->get($this->showUrl($assignment, $deliverable))->assertOk()->assertSeeText('Initial concepts')->assertSeeText('Second line')
            ->assertSeeTextInOrder(['Version 2', 'latest-concepts.txt', 'Version 1', 'first-concepts.txt'])
            ->assertSeeText('2.00 MiB')->assertSeeText('24 October 2026, 12:00 am')->assertSeeText('Latest version')
            ->assertSeeText($assignment->freelancer->name)->assertSeeText('Updated note')->assertSeeText('Original note')
            ->assertSee(route('projects.deliverables.download', [$assignment->project, $deliverable, $first]), false)
            ->assertSee(route('projects.deliverables.download', [$assignment->project, $deliverable, $latest]), false)
            ->assertDontSeeText($participant === 'client' ? $assignment->freelancer->email : $assignment->project->client->email)
            ->assertDontSeeText('Private freelancer biography')->assertDontSee($first->storage_path, false)
            ->assertDontSee($first->submission_id, false)->assertDontSeeText('Accept deliverable')->assertDontSeeText('Request revision');
        $this->assertDatabaseCount('deliverable_versions', 2);
    }

    public function test_owner_client_has_downloads_but_no_upload_form_and_cannot_invoke_save(): void
    {
        $assignment = $this->assignment();
        $deliverable = $this->deliverable($assignment);
        $this->version($deliverable);
        $this->actingAs($assignment->project->client);
        $this->get($this->indexUrl($assignment))->assertOk()->assertDontSee('wire:submit="save"', false)->assertSeeText('Only the assigned Freelancer can upload files');
        $this->get($this->showUrl($assignment, $deliverable))->assertOk()->assertDontSee('wire:submit="save"', false);
        Volt::test('projects.deliverables.index', ['project' => $assignment->project])->call('save')->assertForbidden();
        Volt::test('projects.deliverables.show', ['project' => $assignment->project, 'deliverable' => $deliverable])->call('save')->assertForbidden();
        $this->assertDatabaseCount('deliverable_versions', 1);
    }

    public function test_assignment_is_required_and_get_requests_never_submit_files(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client)->get(route('projects.deliverables.index', $project))->assertNotFound();
        $assignment = $this->assignment();
        $this->actingAs($assignment->freelancer)->get($this->indexUrl($assignment).'?save=1&title=Forged')->assertOk()->assertSeeText('No deliverables yet.');
        $this->assertDatabaseCount('deliverables', 0);
        $this->assertDatabaseCount('deliverable_versions', 0);
        $this->post($this->indexUrl($assignment))->assertStatus(405);
    }

    public function test_mismatched_nested_deliverables_are_not_found(): void
    {
        $assignment = $this->assignment();
        $other = $this->assignment();
        $deliverable = $this->deliverable($other, ['title' => 'Other project secret']);
        $this->actingAs($assignment->project->client)->get($this->showUrl($assignment, $deliverable))->assertNotFound()->assertDontSeeText($deliverable->title);
        $this->actingAs($assignment->freelancer);
        Volt::test('projects.deliverables.show', ['project' => $assignment->project, 'deliverable' => $deliverable])->assertNotFound();
    }

    #[DataProvider('historicalStatuses')]
    public function test_both_participants_retain_read_access_and_upload_controls_disappear_after_status_changes(ProjectStatus $status): void
    {
        $assignment = $this->assignment();
        $deliverable = $this->deliverable($assignment);
        $this->version($deliverable);
        $assignment->project->forceFill(['status' => $status])->save();
        foreach ([$assignment->project->client, $assignment->freelancer] as $user) {
            $this->actingAs($user)->get($this->indexUrl($assignment))->assertOk()->assertSeeText($deliverable->title)->assertDontSee('wire:submit="save"', false);
            $this->get($this->showUrl($assignment, $deliverable))->assertOk()->assertSeeText('Complete version history')->assertDontSee('wire:submit="save"', false);
        }
    }

    public function test_list_is_paginated_deterministic_and_scoped_to_the_current_assignment(): void
    {
        $assignment = $this->assignment();
        $oldest = $this->deliverable($assignment, ['title' => 'Oldest submitted work']);
        for ($index = 1; $index <= 10; $index++) {
            $this->deliverable($assignment, ['title' => 'Deliverable item '.$index]);
        }
        $foreign = $this->deliverable($this->assignment(), ['title' => 'Unrelated secret work']);
        $this->actingAs($assignment->project->client);
        $this->get($this->indexUrl($assignment))->assertOk()->assertSeeTextInOrder(['Deliverable item 10', 'Deliverable item 9', 'Deliverable item 1'])
            ->assertDontSeeText($oldest->title)->assertDontSeeText($foreign->title);
        $this->get($this->indexUrl($assignment).'?page=2')->assertOk()->assertSeeText($oldest->title)->assertDontSeeText('Deliverable item 10')->assertDontSeeText($foreign->title);
    }

    public function test_new_form_shows_only_current_assignment_milestones_and_accessible_upload_feedback(): void
    {
        $assignment = $this->assignment();
        Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'title' => 'Current assignment milestone']);
        Milestone::factory()->create(['title' => 'Private foreign milestone']);
        $response = $this->actingAs($assignment->freelancer)->get($this->indexUrl($assignment))->assertOk()
            ->assertSeeText('Current assignment milestone')->assertDontSeeText('Private foreign milestone')->assertSeeText('Maximum 10 MiB')
            ->assertSeeText('Deliverable title')->assertSeeText('Submission note (optional)')->assertSeeText('Submit deliverable')
            ->assertSee('wire:loading.attr="disabled"', false)->assertSee('aria-live="polite"', false)->assertSee('x-on:livewire-upload-error', false)
            ->assertSee('for="deliverable-file-upload"', false)->assertSee('id="deliverable-file-upload"', false)->assertSee('wire:model="file" type="file"', false)
            ->assertSee('aria-labelledby="deliverable-file-label"', false)->assertSee('id="deliverable-file-label"', false)
            ->assertSee('aria-describedby="deliverable-file-help deliverable-file-error"', false)->assertSee('id="deliverable-file-help"', false)->assertSee('id="deliverable-file-error"', false)
            ->assertSee('data-flux-control class="block w-full', false)->assertDontSee('data-flux-input-file', false)
            ->assertDontSee('temporaryUrl', false)->assertDontSee('/storage/', false);
        $this->assertNativeFileControl($response->getContent());
    }

    public function test_freelancer_submits_a_deliverable_and_redirects_to_its_history(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->freelancer);
        $form = $this->form($assignment)->set('title', '  First delivery  ')->set('description', "Description\nSecond line")
            ->set('milestone_id', (string) $milestone->id)->set('submission_note', 'Please review this version.')->set('file', $this->file());
        $submissionId = $form->get('submissionId');
        $form->call('save')->assertHasNoErrors();
        $deliverable = Deliverable::sole();
        $version = DeliverableVersion::sole();
        $form->assertRedirect($this->showUrl($assignment, $deliverable))->assertSet('submissionId', $submissionId);
        $this->assertSame('First delivery', $deliverable->title);
        $this->assertSame($milestone->id, $deliverable->milestone_id);
        $this->assertSame($assignment->freelancer_id, $version->uploaded_by);
        $this->assertSame($submissionId, $version->submission_id);
        Storage::disk('deliverables')->assertExists($version->storage_path);
    }

    public function test_new_version_form_keeps_deliverable_metadata_read_only_and_preserves_earlier_versions(): void
    {
        $assignment = $this->assignment();
        $deliverable = $this->deliverable($assignment, ['title' => 'Approved brief name', 'description' => 'Original description']);
        $first = $this->version($deliverable, ['submission_note' => 'Original submission']);
        $before = $first->fresh()->getAttributes();
        $this->actingAs($assignment->freelancer);
        $response = $this->get($this->showUrl($assignment, $deliverable))->assertOk()->assertSeeText('Submit a new version')->assertDontSee('wire:model="title"', false)
            ->assertDontSee('wire:model="description"', false)->assertDontSee('wire:model="milestone_id"', false)
            ->assertSee('for="deliverable-file-upload"', false)->assertSee('id="deliverable-file-upload"', false)
            ->assertSee('aria-labelledby="deliverable-file-label"', false)->assertSee('aria-describedby="deliverable-file-help deliverable-file-error"', false)
            ->assertSee('data-flux-control class="block w-full', false)->assertDontSee('data-flux-input-file', false);
        $this->assertNativeFileControl($response->getContent());
        Volt::test('projects.deliverables.show', ['project' => $assignment->project, 'deliverable' => $deliverable])
            ->set('submission_note', 'Second submitted version')->set('file', $this->file())->call('save')->assertHasNoErrors()
            ->assertRedirect($this->showUrl($assignment, $deliverable));
        $this->assertDatabaseCount('deliverables', 1);
        $this->assertDatabaseCount('deliverable_versions', 2);
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertSame('Approved brief name', $deliverable->fresh()->title);
        $this->assertSame('Original description', $deliverable->fresh()->description);
        $this->assertSame(2, $deliverable->fresh()->latestVersion->version_number);
    }

    public function test_validation_keeps_entered_details_and_retry_identifier_without_writing(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->freelancer);
        $form = $this->form($assignment)->set('title', '   ')->set('description', 'Retained description')->set('submission_note', 'Retained note')->set('file', $this->file());
        $submissionId = $form->get('submissionId');
        $form->call('save')->assertHasErrors(['title'])->assertSet('description', 'Retained description')->assertSet('submission_note', 'Retained note')->assertSet('submissionId', $submissionId);
        $this->assertDatabaseCount('deliverables', 0);
        $this->assertDatabaseCount('deliverable_versions', 0);
        $form->set('title', 'Corrected title')->call('save')->assertHasNoErrors();
        $this->assertSame($submissionId, DeliverableVersion::sole()->submission_id);
    }

    public function test_duplicate_livewire_submission_reuses_the_original_record_file_and_timestamp(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->freelancer);
        $form = $this->form($assignment)->set('title', 'Initial title')->set('file', $this->file())->call('save')->assertHasNoErrors();
        $version = DeliverableVersion::sole();
        $before = $version->getAttributes();
        $files = Storage::disk('deliverables')->allFiles();
        $this->travel(1)->hours();
        $form->set('title', 'Changed retry title')->set('submission_note', 'Changed retry note')->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('deliverables', 1);
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame($before, $version->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('deliverables')->allFiles());
        $this->assertSame('Initial title', $version->deliverable->title);
    }

    public function test_stale_form_cannot_upload_after_project_stops_being_in_progress(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->freelancer);
        $form = $this->form($assignment)->set('title', 'Stale submission')->set('file', $this->file());
        $assignment->project->forceFill(['status' => ProjectStatus::Completed])->save();
        $form->call('save')->assertHasErrors(['deliverables'])->assertSet('title', 'Stale submission');
        $this->assertDatabaseCount('deliverables', 0);
        $this->assertDatabaseCount('deliverable_versions', 0);
    }

    public function test_response_failure_preserves_the_committed_file_and_the_same_form_retries_without_duplicate_writes(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->freelancer);
        $form = $this->form($assignment)->set('title', 'Response failure delivery')->set('file', $this->file());
        $component = Mockery::mock(get_class($form->instance()))->makePartial();
        $component->projectId = $assignment->project_id;
        $component->submissionId = $form->get('submissionId');
        $component->title = 'Response failure delivery';
        $component->file = $form->get('file');
        $component->shouldReceive('redirectRoute')->once()->andThrow(new RuntimeException('Simulated response-stage failure.'));
        try {
            $component->save();
            $this->fail('The simulated response failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated response-stage failure.', $exception->getMessage());
        }

        $version = DeliverableVersion::sole();
        $before = $version->getAttributes();
        $files = Storage::disk('deliverables')->allFiles();
        Storage::disk('deliverables')->assertExists($version->storage_path);
        $assignment->project->forceFill(['status' => ProjectStatus::Completed])->save();
        $form->call('save')->assertHasNoErrors()->assertRedirect($this->showUrl($assignment, $version->deliverable));
        $this->assertDatabaseCount('deliverables', 1);
        $this->assertDatabaseCount('deliverable_versions', 1);
        $this->assertSame($before, $version->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('deliverables')->allFiles());
    }

    #[DataProvider('lockedIdentifiers')]
    public function test_locked_project_deliverable_and_retry_identifiers_cannot_be_replaced(string $page, string $property): void
    {
        $assignment = $this->assignment();
        $deliverable = $this->deliverable($assignment);
        $this->actingAs($assignment->freelancer);
        $parameters = ['project' => $assignment->project];
        if ($page === 'show') {
            $parameters['deliverable'] = $deliverable;
        }
        $form = Volt::test('projects.deliverables.'.$page, $parameters);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $form->set($property, $property === 'submissionId' ? 'changed-submission-token' : 999999);
    }

    public function test_user_written_metadata_filenames_notes_and_milestones_are_escaped(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'title' => '<script>milestone()</script>']);
        $deliverable = $this->deliverable($assignment, ['milestone_id' => $milestone->id, 'title' => '<script>title()</script>', 'description' => "<img src=x onerror=alert(1)>\nDescription line"]);
        $version = $this->version($deliverable, ['original_filename' => '<script>file()</script>.txt', 'submission_note' => "<script>note()</script>\nNote line"]);
        $this->actingAs($assignment->project->client);
        $this->get($this->indexUrl($assignment))->assertOk()->assertSee(e($deliverable->title), false)->assertDontSee($deliverable->title, false)
            ->assertSee(e($version->original_filename), false)->assertDontSee($version->original_filename, false);
        $this->get($this->showUrl($assignment, $deliverable))->assertOk()->assertSee(e($deliverable->description), false)
            ->assertSee(e($version->submission_note), false)->assertSee(e($milestone->title), false)->assertDontSee($version->submission_note, false)
            ->assertSee('whitespace-pre-wrap', false);
    }

    public function test_both_parties_have_working_deliverable_links_from_their_project_and_plan_pages(): void
    {
        $assignment = $this->assignment();
        $url = $this->indexUrl($assignment);
        $this->actingAs($assignment->project->client)->get(route('client.projects.show', $assignment->project))->assertOk()->assertSee($url, false)->assertSeeText('Deliverables');
        $this->get(route('projects.plan', $assignment->project))->assertOk()->assertSee($url, false);
        $this->actingAs($assignment->freelancer)->get(route('freelancer.assignments.show', $assignment))->assertOk()->assertSee($url, false)->assertSeeText('Deliverables');
        $this->get(route('projects.plan', $assignment->project))->assertOk()->assertSee($url, false);
    }

    public function test_account_role_changes_revoke_even_historical_participant_access(): void
    {
        $assignment = $this->assignment();
        $deliverable = $this->deliverable($assignment);
        $freelancer = $assignment->freelancer;
        $this->actingAs($freelancer);
        $form = Volt::test('projects.deliverables.show', ['project' => $assignment->project, 'deliverable' => $deliverable]);
        $freelancer->forceFill(['role' => User::ROLE_ADMIN])->save();
        $form->call('save')->assertForbidden();
        $this->get($this->showUrl($assignment, $deliverable))->assertForbidden();
    }

    public function test_empty_history_and_empty_paginated_page_remain_useful(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client)->get($this->indexUrl($assignment))->assertOk()->assertSeeText('No deliverables yet.')->assertSeeText('Files submitted by the assigned Freelancer will appear here.');
        $deliverable = $this->deliverable($assignment);
        $this->get($this->showUrl($assignment, $deliverable))->assertOk()->assertSeeText('No versions yet.');
        $this->get($this->indexUrl($assignment).'?page=2')->assertOk()->assertSeeText('No deliverables on this page.');
    }

    private function assignment(): ProjectAssignment
    {
        return ProjectAssignment::factory()->create(['agreed_deadline' => '2026-11-10']);
    }

    private function assertNativeFileControl(string $html): void
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $nodes = (new DOMXPath($document))->query('//input[@id="deliverable-file-upload"]');
        $this->assertCount(1, $nodes);
        $input = $nodes->item(0);
        $this->assertSame('file', $input->getAttribute('type'));
        $this->assertNotSame('-1', $input->getAttribute('tabindex'));
        $this->assertFalse($input->hasAttribute('hidden'));
        $this->assertStringNotContainsString('sr-only', $input->getAttribute('class'));
        $this->assertTrue($input->hasAttribute('data-flux-control'));
    }

    private function deliverable(ProjectAssignment $assignment, array $changes = []): Deliverable
    {
        return Deliverable::factory()->create(array_replace(['project_assignment_id' => $assignment->id], $changes));
    }

    private function version(Deliverable $deliverable, array $changes = []): DeliverableVersion
    {
        return DeliverableVersion::factory()->create(array_replace(['deliverable_id' => $deliverable->id], $changes));
    }

    private function participant(ProjectAssignment $assignment, string $participant): User
    {
        return $participant === 'client' ? $assignment->project->client : $assignment->freelancer;
    }

    private function form(ProjectAssignment $assignment): Testable
    {
        return Volt::test('projects.deliverables.index', ['project' => $assignment->project]);
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('delivery.txt', "The submitted work.\nSecond line.");
    }

    private function indexUrl(ProjectAssignment $assignment): string
    {
        return route('projects.deliverables.index', $assignment->project);
    }

    private function showUrl(ProjectAssignment $assignment, Deliverable $deliverable): string
    {
        return route('projects.deliverables.show', [$assignment->project, $deliverable]);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function participants(): array
    {
        return [['client'], ['freelancer']];
    }

    public static function historicalStatuses(): array
    {
        return array_map(fn ($status) => [$status], array_filter(ProjectStatus::cases(), fn ($status) => $status !== ProjectStatus::InProgress));
    }

    public static function lockedIdentifiers(): array
    {
        return [['index', 'projectId'], ['index', 'submissionId'], ['show', 'projectId'], ['show', 'deliverableId'], ['show', 'submissionId']];
    }
}
