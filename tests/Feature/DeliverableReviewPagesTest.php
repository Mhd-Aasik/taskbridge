<?php

namespace Tests\Feature;

use App\Enums\DeliverableReviewDecision;
use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\FreelancerProfile;
use App\Models\ProjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliverableReviewPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
    }

    public function test_owner_sees_accessible_confirmation_and_get_does_not_review(): void
    {
        [$assignment, $deliverable, $version] = $this->work();
        $this->actingAs($assignment->project->client)->get($this->showUrl($assignment, $deliverable).'?decision=approved&confirmed=1')
            ->assertOk()->assertSeeText('Review latest version')->assertSeeText('Reviewing version 1')
            ->assertSeeText('Approve version')->assertSeeText('Request changes')->assertSeeText('Review remarks')
            ->assertSeeText('I confirm approval of this version.')->assertSeeText('It does not mark the whole project completed or paid.')
            ->assertSee('aria-labelledby="approve-version-heading"', false)->assertSee('aria-describedby="approve-version-explanation"', false)
            ->assertSee('wire:submit="approveVersion"', false)->assertSee('wire:loading.attr="disabled"', false)
            ->assertDontSee('wire:model="decision"', false)->assertDontSee('wire:model="reviewed_by"', false);
        $this->assertDatabaseCount('deliverable_reviews', 0);
        $this->assertSame($version->id, $this->form($assignment, $deliverable)->get('reviewVersionId'));
    }

    public function test_guests_cannot_read_or_review(): void
    {
        [$assignment, $deliverable] = $this->work();
        $this->get($this->showUrl($assignment, $deliverable))->assertRedirect(route('login'));
        $this->assertDatabaseCount('deliverable_reviews', 0);
    }

    #[DataProvider('unrelatedRoles')]
    public function test_unrelated_accounts_have_no_access(string $role): void
    {
        [$assignment, $deliverable] = $this->work();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get($this->showUrl($assignment, $deliverable))->assertForbidden();
        $this->form($assignment, $deliverable)->assertForbidden();
        $this->assertDatabaseCount('deliverable_reviews', 0);
    }

    public function test_assigned_freelancer_reads_state_without_review_controls_and_cannot_invoke_review(): void
    {
        [$assignment, $deliverable] = $this->work();
        $this->actingAs($assignment->freelancer)->get($this->showUrl($assignment, $deliverable))->assertOk()
            ->assertSeeText('Awaiting Client review.')->assertSeeText('Submit a new version')
            ->assertDontSeeText('Review latest version')->assertDontSeeText('Confirm approval');
        $this->form($assignment, $deliverable)->set('reviewConfirmed', true)->call('approveVersion')->assertForbidden();
        $this->form($assignment, $deliverable)->set('reviewRemarks', 'Please revise it.')->call('requestChanges')->assertForbidden();
        $this->assertDatabaseCount('deliverable_reviews', 0);
    }

    public function test_owner_approval_requires_confirmation_and_records_optional_remarks(): void
    {
        [$assignment, $deliverable, $version] = $this->work();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment, $deliverable)->call('approveVersion')->assertHasErrors(['confirmed']);
        $this->assertDatabaseCount('deliverable_reviews', 0);
        $form->set('reviewConfirmed', true)->call('approveVersion')->assertHasNoErrors()
            ->assertSeeText('Version approved. Project completion and payment have not changed.')
            ->assertSeeText('This deliverable is approved.')->assertDontSeeText('Review latest version');
        $review = DeliverableReview::sole();
        $this->assertSame($version->id, $review->deliverable_version_id);
        $this->assertSame($assignment->project->client_id, $review->reviewed_by);
        $this->assertSame(DeliverableReviewDecision::Approved, $review->decision);
        $this->assertNull($review->remarks);
        $this->assertSame(ProjectStatus::InProgress, $assignment->project->fresh()->status);
    }

    public function test_meaningful_change_request_is_saved_and_confirmation_is_not_required(): void
    {
        [$assignment, $deliverable] = $this->work();
        $this->actingAs($assignment->project->client);
        $this->form($assignment, $deliverable)->set('reviewRemarks', "  Please align the headings.\nKeep the second page.  ")->call('requestChanges')
            ->assertHasNoErrors()->assertSeeText('Changes requested. The Freelancer can submit a new version with a response note.')
            ->assertSeeText('Please align the headings.')->assertDontSeeText('Review latest version');
        $review = DeliverableReview::sole();
        $this->assertSame(DeliverableReviewDecision::ChangesRequested, $review->decision);
        $this->assertSame("Please align the headings.\nKeep the second page.", $review->remarks);
        $this->actingAs($assignment->freelancer)->get($this->showUrl($assignment, $deliverable))->assertOk()
            ->assertSeeText('Changes requested.')->assertSeeText('response in the submission note')->assertSeeText('Submit a new version');
    }

    #[DataProvider('invalidRemarks')]
    public function test_change_request_validation_keeps_entered_remarks_and_stored_work_unchanged(string $remarks): void
    {
        [$assignment, $deliverable, $version] = $this->work();
        $before = $version->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        $this->form($assignment, $deliverable)->set('reviewRemarks', $remarks)->call('requestChanges')
            ->assertHasErrors(['remarks'])->assertSet('reviewRemarks', $remarks);
        $this->assertDatabaseCount('deliverable_reviews', 0);
        $this->assertSame($before, $version->fresh()->getAttributes());
    }

    public function test_maximum_length_remarks_are_accepted_and_excessive_approval_remarks_are_rejected(): void
    {
        [$assignment, $deliverable] = $this->work();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment, $deliverable)->set('reviewConfirmed', true)->set('reviewRemarks', str_repeat('a', 5001))
            ->call('approveVersion')->assertHasErrors(['remarks']);
        $form->set('reviewRemarks', str_repeat('a', 5000))->call('approveVersion')->assertHasNoErrors();
        $this->assertSame(str_repeat('a', 5000), DeliverableReview::sole()->remarks);
    }

    public function test_stale_form_preserves_target_until_owner_explicitly_reads_the_latest_submission(): void
    {
        [$assignment, $deliverable, $first] = $this->work();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment, $deliverable)->set('reviewRemarks', 'Review notes kept.')->set('reviewConfirmed', true);
        $latest = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $form->call('approveVersion')->assertHasErrors(['review'])->assertSet('reviewVersionId', $first->id)
            ->assertSet('reviewRemarks', 'Review notes kept.')->assertSeeText('A newer version has been submitted.')
            ->assertSeeText('Review latest submission');
        $this->assertDatabaseCount('deliverable_reviews', 0);
        $form->call('refreshReviewTarget')->assertSet('reviewVersionId', $latest->id)->assertSet('reviewConfirmed', false)
            ->assertSet('reviewRemarks', 'Review notes kept.')->assertSeeText('Reviewing version 2')
            ->call('requestChanges')->assertHasNoErrors();
        $this->assertSame($latest->id, DeliverableReview::sole()->deliverable_version_id);
    }

    public function test_exact_retry_preserves_review_and_conflicting_remarks_are_refused(): void
    {
        [$assignment, $deliverable] = $this->work();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment, $deliverable)->set('reviewRemarks', 'Please revise this heading.')->call('requestChanges')->assertHasNoErrors();
        $review = DeliverableReview::sole();
        $before = $review->getAttributes();
        $this->travel(2)->hours();
        $form->call('requestChanges')->assertHasNoErrors();
        $this->assertSame($before, $review->fresh()->getAttributes());
        $form->set('reviewRemarks', 'A different request.')->call('requestChanges')->assertHasErrors(['review']);
        $this->assertSame($before, $review->fresh()->getAttributes());
        $this->assertDatabaseCount('deliverable_reviews', 1);
    }

    public function test_same_page_retry_after_new_version_does_not_review_the_new_submission(): void
    {
        [$assignment, $deliverable, $first] = $this->work();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment, $deliverable)->set('reviewRemarks', 'Please revise.')->call('requestChanges')->assertHasNoErrors();
        $before = DeliverableReview::sole()->getAttributes();
        DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $form->call('requestChanges')->assertHasNoErrors()->assertSet('reviewVersionId', $first->id)
            ->assertSeeText('A newer version has been submitted.');
        $this->assertSame($before, DeliverableReview::sole()->getAttributes());
    }

    public function test_stale_owner_form_cannot_review_after_project_status_changes(): void
    {
        [$assignment, $deliverable] = $this->work();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment, $deliverable)->set('reviewRemarks', 'Please revise.');
        $assignment->project->forceFill(['status' => ProjectStatus::Completed])->save();
        $form->call('requestChanges')->assertHasErrors(['review'])->assertSet('reviewRemarks', 'Please revise.');
        $this->assertDatabaseCount('deliverable_reviews', 0);
    }

    public function test_persisted_role_change_revokes_an_already_open_owner_review_form(): void
    {
        [$assignment, $deliverable] = $this->work();
        $client = $assignment->project->client;
        $this->actingAs($client);
        $form = $this->form($assignment, $deliverable)->set('reviewRemarks', 'Please revise.');
        $client->forceFill(['role' => User::ROLE_ADMIN])->save();
        $form->call('requestChanges')->assertForbidden();
        $this->assertDatabaseCount('deliverable_reviews', 0);
    }

    public function test_locked_review_version_cannot_be_replaced_by_client_input(): void
    {
        [$assignment, $deliverable] = $this->work();
        $this->actingAs($assignment->project->client);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->form($assignment, $deliverable)->set('reviewVersionId', 999999);
    }

    public function test_mismatched_nested_deliverable_cannot_expose_review_information(): void
    {
        [$assignment] = $this->work();
        [, $foreign, $version] = $this->work();
        DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $version->id, 'remarks' => 'Private foreign remarks']);
        $this->actingAs($assignment->project->client)->get($this->showUrl($assignment, $foreign))->assertNotFound()->assertDontSeeText('Private foreign remarks');
        $this->form($assignment, $foreign)->assertNotFound();
    }

    public function test_both_participants_see_complete_escaped_review_history_and_sri_lankan_time(): void
    {
        [$assignment, $deliverable, $first] = $this->work();
        $remarks = "<script>alert('review')</script>\nKeep this second line.";
        $client = $assignment->project->client;
        $client->forceFill(['name' => '<b>Client reviewer</b>'])->save();
        $review = DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $first->id, 'remarks' => $remarks, 'reviewed_at' => '2026-10-23 18:30:00']);
        $latest = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        FreelancerProfile::factory()->create(['user_id' => $assignment->freelancer_id, 'bio' => 'Private profile text']);

        foreach ([$client, $assignment->freelancer] as $participant) {
            $response = $this->actingAs($participant)->get($this->showUrl($assignment, $deliverable))->assertOk()
                ->assertSeeTextInOrder(['Version 2', 'Awaiting review', 'Version 1', 'Changes requested'])
                ->assertSee(e($remarks), false)->assertDontSee($remarks, false)->assertSee(e($client->name), false)
                ->assertDontSee($client->name, false)->assertSeeText('24 October 2026, 12:00 am')
                ->assertSee('whitespace-pre-wrap', false)->assertSee(route('projects.deliverables.download', [$assignment->project, $deliverable, $first]), false)
                ->assertSee(route('projects.deliverables.download', [$assignment->project, $deliverable, $latest]), false)
                ->assertDontSeeText($participant->id === $client->id ? $assignment->freelancer->email : $client->email)
                ->assertDontSeeText('Private profile text')->assertDontSee($first->storage_path, false);
            $this->assertStringNotContainsString('Superseded', $response->getContent());
        }
        $this->assertSame($review->reviewed_at->toIso8601String(), $review->fresh()->reviewed_at->toIso8601String());
    }

    public function test_unreviewed_older_versions_display_as_superseded_without_mutating_them(): void
    {
        [$assignment, $deliverable, $first] = $this->work();
        DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $before = $first->fresh()->getAttributes();
        $this->actingAs($assignment->project->client)->get($this->showUrl($assignment, $deliverable))->assertOk()
            ->assertSeeTextInOrder(['Version 2', 'Latest version', 'Awaiting review', 'Version 1', 'Superseded'])
            ->assertSeeText('This unreviewed version was superseded by a newer submission.');
        $this->assertSame($before, $first->fresh()->getAttributes());
    }

    public function test_approval_closes_only_the_approved_deliverable_upload_form(): void
    {
        [$assignment, $approved, $version] = $this->work();
        DeliverableReview::factory()->create(['deliverable_version_id' => $version->id]);
        $other = Deliverable::factory()->create(['project_assignment_id' => $assignment->id]);
        DeliverableVersion::factory()->create(['deliverable_id' => $other->id]);
        $this->actingAs($assignment->freelancer)->get($this->showUrl($assignment, $approved))->assertOk()
            ->assertSeeText('This deliverable is approved. Further versions are closed; other deliverables are unaffected.')
            ->assertDontSeeText('Submit a new version');
        $this->get($this->showUrl($assignment, $other))->assertOk()->assertSeeText('Submit a new version');
        $this->get(route('projects.deliverables.index', $assignment->project))->assertOk()->assertSeeText('Submit a deliverable');
    }

    #[DataProvider('historyStatuses')]
    public function test_completed_review_history_remains_readable_when_project_is_not_in_progress(ProjectStatus $status): void
    {
        [$assignment, $deliverable, $version] = $this->work();
        DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $version->id, 'remarks' => 'Historical review remarks']);
        $assignment->project->forceFill(['status' => $status])->save();
        foreach ([$assignment->project->client, $assignment->freelancer] as $participant) {
            $this->actingAs($participant)->get($this->showUrl($assignment, $deliverable))->assertOk()
                ->assertSeeText('Historical review remarks')->assertSeeText('Complete version history')
                ->assertDontSeeText('Review latest version')->assertDontSeeText('Submit a new version');
        }
    }

    public function test_list_summary_uses_latest_versions_and_is_independent_of_pagination_and_other_projects(): void
    {
        [$assignment, $deliverable, $first] = $this->work();
        DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $first->id]);
        DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        $approved = Deliverable::factory()->create(['project_assignment_id' => $assignment->id]);
        $approvedVersion = DeliverableVersion::factory()->create(['deliverable_id' => $approved->id]);
        DeliverableReview::factory()->create(['deliverable_version_id' => $approvedVersion->id]);
        $changes = Deliverable::factory()->create(['project_assignment_id' => $assignment->id]);
        $changesVersion = DeliverableVersion::factory()->create(['deliverable_id' => $changes->id]);
        DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $changesVersion->id]);
        for ($index = 0; $index < 8; $index++) {
            $work = Deliverable::factory()->create(['project_assignment_id' => $assignment->id]);
            DeliverableVersion::factory()->create(['deliverable_id' => $work->id]);
        }
        [, , $foreign] = $this->work();
        DeliverableReview::factory()->create(['deliverable_version_id' => $foreign->id]);
        $this->actingAs($assignment->project->client);
        $summary = ['total' => 11, 'approved' => 1, 'awaiting_review' => 9, 'changes_requested' => 1];
        Volt::test('projects.deliverables.index', ['project' => $assignment->project])->assertViewHas('reviewSummary', $summary)
            ->call('gotoPage', 2)->assertViewHas('reviewSummary', $summary)->assertSeeText('Deliverable review summary');
        $this->get(route('projects.deliverables.index', $assignment->project).'?page=2')->assertOk()->assertSeeText('Based on each deliverable’s latest version, across all pages.');
    }

    public function test_empty_list_and_empty_history_have_zero_review_states_and_no_controls(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        Volt::test('projects.deliverables.index', ['project' => $assignment->project])
            ->assertViewHas('reviewSummary', ['total' => 0, 'approved' => 0, 'awaiting_review' => 0, 'changes_requested' => 0])
            ->assertSeeText('No deliverables yet.');
        $deliverable = Deliverable::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->get($this->showUrl($assignment, $deliverable))->assertOk()->assertSeeText('No versions yet.')->assertDontSeeText('Review latest version');
    }

    private function work(): array
    {
        $assignment = ProjectAssignment::factory()->create();
        $deliverable = Deliverable::factory()->create(['project_assignment_id' => $assignment->id]);
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);

        return [$assignment, $deliverable, $version];
    }

    private function form(ProjectAssignment $assignment, Deliverable $deliverable): Testable
    {
        return Volt::test('projects.deliverables.show', ['project' => $assignment->project, 'deliverable' => $deliverable]);
    }

    private function showUrl(ProjectAssignment $assignment, Deliverable $deliverable): string
    {
        return route('projects.deliverables.show', [$assignment->project, $deliverable]);
    }

    public static function unrelatedRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function invalidRemarks(): array
    {
        return [[''], ['   '], ["\n\t\r"], [str_repeat('x', 5001)]];
    }

    public static function historyStatuses(): array
    {
        return array_map(fn ($status) => [$status], array_filter(ProjectStatus::cases(), fn ($status) => $status !== ProjectStatus::InProgress));
    }
}
