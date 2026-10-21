<?php

namespace Tests\Feature;

use App\Actions\Disputes\SubmitDisputeStatement;
use App\Models\Deliverable;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\DisputeStatement;
use App\Models\FreelancerProfile;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DisputePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 18:45:00', 'UTC'));
        Storage::fake('deliverables');
    }

    public function test_all_private_routes_redirect_guests_and_gets_never_create_records(): void
    {
        $case = DisputeCase::factory()->create();
        $version = $this->version($case);
        foreach ([route('disputes.index'), route('disputes.show', $case), route('projects.disputes.create', $case->assignment->project_id), route('admin.disputes.index'), route('admin.disputes.show', $case), route('admin.disputes.download', [$case, $version])] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->assertDatabaseCount('dispute_cases', 1);
        $this->assertDatabaseCount('dispute_statements', 0);
        $this->assertDatabaseCount('dispute_resolutions', 0);
    }

    #[DataProvider('participantRoles')]
    public function test_both_participants_open_view_and_follow_working_notices(string $role): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $user = $role === 'client' ? $assignment->project->client : $assignment->freelancer;
        $this->actingAs($user);
        $this->get(route('projects.disputes.create', $assignment->project_id))->assertOk()->assertSeeText('Confirm open dispute')->assertSee('aria-labelledby="open-dispute-heading"', false);
        $this->assertDatabaseCount('dispute_cases', 0);
        Volt::test('disputes.create', ['project' => $assignment->project])->set('title', 'Clarify this outcome')->set('reason', 'Please clarify the delivered outcome.')
            ->call('openDispute')->assertHasErrors(['confirmed'])->assertSet('reason', 'Please clarify the delivered outcome.')
            ->set('confirmed', true)->call('openDispute')->assertHasNoErrors()->assertRedirect(route('disputes.show', DisputeCase::sole()));
        $case = DisputeCase::sole();
        $this->get(route('disputes.show', $case))->assertOk()->assertSeeText('Current project context')->assertSeeText('No statements yet.');
        foreach (['projects.plan', 'projects.payment', 'projects.completion'] as $route) {
            $this->get(route($route, $assignment->project_id))->assertOk()->assertSeeText('active dispute')->assertSee(route('disputes.show', $case));
        }
        $detail = $role === 'client' ? route('client.projects.show', $assignment->project_id) : route('freelancer.assignments.show', $assignment);
        $this->get($detail)->assertOk()->assertSee(route('disputes.show', $case));
        $this->get(route('dashboard'))->assertRedirect();
        $this->get(route($role.'.dashboard'))->assertOk()->assertSee(route('disputes.index'));
        $this->assertDatabaseCount('dispute_cases', 1);
        $this->assertDatabaseCount('dispute_statements', 0);
    }

    #[DataProvider('roles')]
    public function test_other_accounts_cannot_use_participant_routes_or_actions(string $role): void
    {
        $case = DisputeCase::factory()->create();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route('projects.disputes.create', $case->assignment->project_id))->assertForbidden();
        $this->get(route('disputes.show', $case))->assertForbidden();
        if ($role !== User::ROLE_ADMIN) {
            $this->get(route('admin.disputes.index'))->assertForbidden();
            $this->get(route('admin.disputes.show', $case))->assertForbidden();
            Volt::test('disputes.show', ['dispute' => $case])->assertForbidden();
        }
        $this->assertDatabaseCount('dispute_statements', 0);
    }

    public function test_unassigned_project_has_no_case_opening_page(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client)->get(route('projects.disputes.create', $project))->assertNotFound();
        $this->assertDatabaseCount('dispute_cases', 0);
    }

    public function test_participant_statements_keep_input_and_show_confirmation_with_explicit_next_statement(): void
    {
        $case = DisputeCase::factory()->create();
        $this->actingAs($case->opener);
        $form = Volt::test('disputes.show', ['dispute' => $case]);
        $form->set('body', '   ')->call('submitStatement')->assertHasErrors(['body'])->assertSet('body', '   ');
        $form->set('body', '  Original statement  ')->call('submitStatement')->assertHasNoErrors()->assertSeeText('Statement recorded.')
            ->assertSeeText('Start another statement')->call('submitStatement')->assertHasNoErrors();
        $this->assertDatabaseCount('dispute_statements', 1);
        $form->call('startStatement')->assertSet('body', '')->set('body', 'A second statement')->call('submitStatement')->assertHasNoErrors();
        $this->assertDatabaseCount('dispute_statements', 2);
        $this->assertSame('Original statement', DisputeStatement::oldest('id')->first()->body);
    }

    public function test_stale_case_form_refuses_new_statements_after_resolution_and_rechecks_persisted_role(): void
    {
        $case = DisputeCase::factory()->create();
        $this->actingAs($case->opener);
        $form = Volt::test('disputes.show', ['dispute' => $case])->set('body', 'Keep entered statement');
        DisputeResolution::factory()->create(['dispute_case_id' => $case->id]);
        $form->call('submitStatement')->assertHasErrors(['statement'])->assertSet('body', 'Keep entered statement');
        DB::table('users')->where('id', $case->opened_by)->update(['role' => User::ROLE_FREELANCER]);
        $form->call('submitStatement')->assertForbidden();
        $this->assertDatabaseCount('dispute_statements', 0);
    }

    public function test_admin_route_defaults_are_explicit_and_cannot_be_switched_by_query_flags(): void
    {
        $case = DisputeCase::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('admin.disputes.index', ['administration' => false, 'adminContext' => false]))->assertOk()->assertSeeText('Admin dispute cases');
        $this->get(route('admin.disputes.show', [$case, 'administration' => false]))->assertOk()->assertSeeText('Record Admin decision');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.disputes.index'))->assertSee('data-testid="open-dispute-count">1', false);
        $this->actingAs($case->opener)->get(route('disputes.show', [$case, 'administration' => true, 'adminContext' => true]))->assertOk()->assertDontSeeText('Record Admin decision');
        $this->get(route('disputes.index', ['administration' => true]))->assertOk()->assertSeeText('Your disputes');
    }

    public function test_admin_clarifies_and_resolves_with_confirmation_and_retained_validation_input(): void
    {
        $case = DisputeCase::factory()->create();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $form = Volt::test('disputes.show', ['dispute' => $case, 'administration' => true]);
        $form->set('body', 'Please clarify the delivery.')->call('submitStatement')->assertHasNoErrors()->assertSeeText('Admin clarification');
        $form->set('rationale', 'Reviewed and clarified outcome.')->call('resolveCase')->assertHasErrors(['resolutionConfirmed'])->assertSet('rationale', 'Reviewed and clarified outcome.')
            ->set('resolutionConfirmed', true)->call('resolveCase')->assertHasNoErrors()->assertSeeText('Recorded Admin decision')->assertSeeText('The case is closed')
            ->assertDontSeeText('Record Admin decision')->call('resolveCase')->assertHasNoErrors();
        $this->assertDatabaseCount('dispute_resolutions', 1);
        $this->assertDatabaseCount('active_disputes', 0);
    }

    public function test_response_failure_after_commit_retains_retryable_statement_without_duplicates(): void
    {
        $case = DisputeCase::factory()->create();
        $real = new SubmitDisputeStatement;
        $mock = Mockery::mock($real::class)->makePartial();
        $mock->shouldReceive('submit')->once()->andReturnUsing(function (...$args) use ($real): never {
            $real->submit(...$args);
            throw new RuntimeException('Simulated response loss');
        });
        $this->app->instance($real::class, $mock);
        $this->actingAs($case->opener);
        $form = Volt::test('disputes.show', ['dispute' => $case])->set('body', 'Retained original')->call('submitStatement')->assertHasErrors(['statement'])->assertSet('body', 'Retained original');
        $before = DisputeStatement::sole()->getAttributes();
        $this->app->instance($real::class, $real);
        $form->call('submitStatement')->assertHasNoErrors();
        $this->assertSame($before, DisputeStatement::sole()->getAttributes());
    }

    public function test_case_context_is_current_escaped_scoped_and_excludes_private_accounts_and_unrelated_work(): void
    {
        $case = DisputeCase::factory()->create(['reason' => "Original\n<script>case()</script>"]);
        $assignment = $case->assignment;
        $freelancer = $assignment->freelancer;
        $freelancer->forceFill(['avatar_path' => 'avatars/private-photo.png'])->save();
        FreelancerProfile::factory()->create(['user_id' => $freelancer->id, 'headline' => 'Private profile secret', 'is_public' => false]);
        DisputeStatement::factory()->create(['dispute_case_id' => $case->id, 'body' => "Statement\n<script>statement()</script>"]);
        $version = $this->version($case);
        DeliverableReview::factory()->changesRequested()->create(['deliverable_version_id' => $version->id, 'remarks' => '<script>review()</script>']);
        $report = PaymentReport::factory()->create(['project_assignment_id' => $assignment->id, 'note' => '<script>payment()</script>']);
        PaymentAcknowledgment::factory()->create(['payment_report_id' => $report->id]);
        $foreign = DisputeCase::factory()->create(['reason' => 'Unrelated case secret']);
        $foreignVersion = $this->version($foreign);
        DisputeResolution::factory()->create(['dispute_case_id' => $case->id, 'rationale' => "Rationale\n<script>rationale()</script>"]);
        foreach ([$case->opener, $freelancer, User::factory()->create(['role' => User::ROLE_ADMIN])] as $actor) {
            $response = $this->actingAs($actor)->get(route($actor->isAdmin() ? 'admin.disputes.show' : 'disputes.show', $case))->assertOk()
                ->assertSeeText('Current project context')->assertSeeText('not a snapshot')->assertSeeText($assignment->project->client->name)->assertSeeText($freelancer->name)
                ->assertSeeText('28 October 2026, 12:15 am')->assertSee('whitespace-pre-wrap', false)
                ->assertDontSee('Private profile secret')->assertDontSee('Unrelated case secret')->assertDontSee($foreignVersion->storage_path)
                ->assertDontSee($freelancer->avatar_path)->assertDontSee($version->storage_path)->assertDontSee($case->submission_id);
            foreach ([$assignment->project->client, $freelancer] as $participant) {
                if (! $actor->is($participant)) {
                    $response->assertDontSee($participant->email);
                }
            }
            foreach (['case', 'statement', 'review', 'payment', 'rationale'] as $text) {
                $response->assertSee('&lt;script&gt;'.$text.'()&lt;/script&gt;', false)->assertDontSee('<script>'.$text.'()</script>', false);
            }
            $response->assertSee(route($actor->isAdmin() ? 'admin.disputes.download' : 'projects.deliverables.download', $actor->isAdmin() ? [$case, $version] : [$assignment->project, $version->deliverable, $version]));
        }
    }

    public function test_admin_download_is_case_scoped_secure_and_does_not_expand_existing_policies(): void
    {
        $case = DisputeCase::factory()->create();
        $version = $this->version($case);
        $other = $this->version(DisputeCase::factory()->create());
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $response = $this->get(route('admin.disputes.download', [$case, $version]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Vary', 'Cookie')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertStreamedContent('Private case file');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('admin.disputes.download', [$case, $other]))->assertNotFound();
        $this->get(route('projects.deliverables.download', [$case->assignment->project, $version->deliverable, $version]))->assertForbidden();
        $this->get(route('projects.plan', $case->assignment->project_id))->assertForbidden();
        $this->get(route('projects.payment', $case->assignment->project_id))->assertForbidden();
        $this->actingAs($case->opener)->get(route('admin.disputes.download', [$case, $version]))->assertForbidden();
    }

    public function test_tampered_storage_paths_and_missing_files_do_not_escape_case_delivery(): void
    {
        $case = DisputeCase::factory()->create();
        $version = $this->version($case);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        Storage::disk('deliverables')->delete($version->storage_path);
        $this->get(route('admin.disputes.download', [$case, $version]))->assertNotFound();
        DB::table('deliverable_versions')->where('id', $version->id)->update(['storage_path' => '../secret.txt']);
        $this->get(route('admin.disputes.download', [$case, $version]))->assertNotFound();
    }

    public function test_list_isolation_counts_filters_and_deterministic_pagination(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $cases = collect();
        for ($i = 1; $i <= 12; $i++) {
            $assignment = ProjectAssignment::factory()->create(['project_id' => Project::factory()->create(['client_id' => $owner->id])->id]);
            $cases->push(DisputeCase::factory()->create(['project_assignment_id' => $assignment->id, 'title' => 'Owned case '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]));
        }
        DisputeResolution::factory()->create(['dispute_case_id' => $cases[0]->id]);
        DisputeResolution::factory()->create(['dispute_case_id' => $cases[1]->id, 'decision' => 'dismissed']);
        DisputeCase::factory()->create(['title' => 'Foreign case secret']);
        $this->actingAs($owner);
        $list = Volt::test('disputes.index')->assertViewHas('counts', ['total' => 12, 'open' => 10, 'resolved' => 1, 'dismissed' => 1])
            ->assertSeeInOrder(['Owned case 12', 'Owned case 11'])->assertDontSee('Owned case 01')->assertDontSee('Foreign case secret');
        $list->call('setPage', 2)->assertSee('Owned case 01')->set('state', 'resolved')->assertSet('paginators.page', 1)->assertSee('Owned case 01')->assertDontSee('Owned case 12')
            ->assertViewHas('counts', ['total' => 12, 'open' => 10, 'resolved' => 1, 'dismissed' => 1]);
        $list->call('clearFilters')->set('order', 'oldest')->assertSeeInOrder(['Owned case 01', 'Owned case 02']);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        Volt::test('disputes.index', ['administration' => true])->assertViewHas('counts', ['total' => 13, 'open' => 11, 'resolved' => 1, 'dismissed' => 1])->assertSee('Foreign case secret');
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_query_parameters_are_rejected(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->get(route('admin.disputes.index', [$field => $value]))->assertUnprocessable();
    }

    public function test_empty_states_and_history_remain_available_after_project_status_changes(): void
    {
        $case = DisputeCase::factory()->create();
        DB::table('projects')->where('id', $case->assignment->project_id)->update(['status' => 'cancelled']);
        $this->actingAs($case->opener)->get(route('disputes.show', $case))->assertOk()->assertSeeText('No deliverables on this page.');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]));
        $this->get(route('disputes.index'))->assertOk()->assertSeeText('No dispute cases yet.');
    }

    #[DataProvider('lockedProperties')]
    public function test_system_component_identities_are_locked(string $component, string $property, mixed $value): void
    {
        $case = DisputeCase::factory()->create();
        $this->actingAs($case->opener);
        $params = $component === 'disputes.create' ? ['project' => $case->assignment->project] : ($component === 'disputes.show' ? ['dispute' => $case] : []);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test($component, $params)->set($property, $value);
    }

    private function version(DisputeCase $case): DeliverableVersion
    {
        $deliverable = Deliverable::factory()->create(['project_assignment_id' => $case->project_assignment_id]);
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id]);
        Storage::disk('deliverables')->put($version->storage_path, 'Private case file');

        return $version;
    }

    public static function participantRoles(): array
    {
        return [['client'], ['freelancer']];
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function invalidFilters(): array
    {
        return [['state', 'unknown'], ['state', ['open']], ['order', 'title'], ['order', ['oldest']], ['page', 0], ['page', 10001], ['page', 'bad']];
    }

    public static function lockedProperties(): array
    {
        return [['disputes.create', 'projectId', 99999], ['disputes.create', 'submissionId', '00000000-0000-4000-8000-000000000000'], ['disputes.show', 'caseId', 99999], ['disputes.show', 'adminContext', true], ['disputes.show', 'statementToken', '00000000-0000-4000-8000-000000000000'], ['disputes.index', 'adminContext', true]];
    }
}
