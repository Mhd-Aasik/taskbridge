<?php

namespace Tests\Feature;

use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProposalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    }

    public function test_acceptance_copies_exact_saved_terms_and_closes_only_other_submitted_offers(): void
    {
        $offer = $this->offer(['proposed_budget' => '9999999999.99']);
        $project = $offer->project;
        $submitted = Proposal::factory()->count(2)->create(['project_id' => $project->id]);
        $rejected = Proposal::factory()->create(['project_id' => $project->id, 'status' => ProposalStatus::Rejected]);
        $withdrawn = Proposal::factory()->create(['project_id' => $project->id, 'status' => ProposalStatus::Withdrawn]);
        $other = $this->offer();
        $before = $offer->fresh()->getAttributes();
        $projectBefore = $project->getAttributes();
        $closedBefore = [$rejected->fresh()->getAttributes(), $withdrawn->fresh()->getAttributes(), $other->fresh()->getAttributes()];
        $this->actingAs($project->client)->post($this->url($offer), [
            'confirmed' => 1, 'proposal_id' => $other->id, 'project_id' => $other->project_id, 'freelancer_id' => $other->freelancer_id,
            'agreed_budget' => '1.00', 'proposed_budget' => '1.00', 'agreed_deadline' => '2099-01-01', 'assigned_at' => '2000-01-01',
            'status' => 'completed', 'role' => 'admin', 'is_public' => true,
        ])->assertRedirect(route('client.projects.proposals.show', [$project, $offer]))->assertSessionHasNoErrors();
        $assignment = ProjectAssignment::sole();
        $this->assertSame($project->id, $assignment->project_id);
        $this->assertSame($offer->id, $assignment->proposal_id);
        $this->assertSame($offer->freelancer_id, $assignment->freelancer_id);
        $this->assertSame('9999999999.99', $assignment->agreed_budget);
        $this->assertSame('2026-10-24', $assignment->agreed_deadline->toDateString());
        $this->assertSame('2026-10-20 12:00:00', $assignment->getRawOriginal('assigned_at'));
        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
        $this->assertSame(ProposalStatus::Accepted, $offer->fresh()->status);
        foreach ($submitted as $proposal) {
            $this->assertSame(ProposalStatus::Rejected, $proposal->fresh()->status);
        }
        $this->assertSame($closedBefore, [$rejected->fresh()->getAttributes(), $withdrawn->fresh()->getAttributes(), $other->fresh()->getAttributes()]);
        foreach (['proposed_budget', 'proposed_deadline', 'submitted_at', 'cover_letter', 'project_id', 'freelancer_id'] as $field) {
            $this->assertSame($before[$field], $offer->fresh()->getRawOriginal($field));
        }
        foreach (['budget', 'deadline', 'title', 'description', 'client_id', 'published_at'] as $field) {
            $this->assertSame($projectBefore[$field], $project->fresh()->getRawOriginal($field));
        }
        $this->get(route('client.projects.proposals.show', [$project, $offer]))->assertOk()
            ->assertSeeText('Proposal accepted.')->assertSeeText('Status: Accepted')->assertSeeText('LKR 9,999,999,999.99')
            ->assertSeeText('20 October 2026, 5:30 pm (Asia/Colombo)')->assertDontSeeText('Confirm acceptance');
    }

    public function test_guest_cannot_post_or_invoke_acceptance(): void
    {
        $offer = $this->offer();
        $this->post($this->url($offer), ['confirmed' => 1])->assertRedirect(route('login'));
        $this->expectException(HttpException::class);
        app(AcceptProposal::class)->accept($offer->project_id, $offer->id, true);
    }

    #[DataProvider('wrongRoles')]
    public function test_non_clients_cannot_accept_even_if_historically_project_owner(string $role): void
    {
        $offer = $this->offer();
        $user = $offer->project->client;
        $user->role = $role;
        $user->save();
        $this->actingAs($user)->post($this->url($offer), ['confirmed' => 1])->assertForbidden();
        $this->assertDatabaseCount('project_assignments', 0);
    }

    public function test_other_clients_and_mismatched_nested_urls_are_denied(): void
    {
        $offer = $this->offer();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]))->post($this->url($offer), ['confirmed' => 1])->assertForbidden();
        $second = $this->offer([], ['client_id' => $offer->project->client_id]);
        $this->actingAs($offer->project->client)->post(route('client.projects.proposals.accept', [$offer->project, $second]), ['confirmed' => 1])->assertNotFound();
        $this->assertDatabaseCount('project_assignments', 0);
    }

    public function test_confirmation_csrf_and_read_only_get_are_enforced(): void
    {
        $offer = $this->offer();
        $this->actingAs($offer->project->client);
        $this->get(route('client.projects.proposals.show', [$offer->project, $offer]))->assertOk()->assertSeeText('Accept proposal')
            ->assertSeeText('Advertised project deadline:')->assertSeeText('Offered fixed budget')->assertSeeText('Advertised project budget')
            ->assertSee('aria-describedby="accept-explanation"', false)->assertSee('name="_token"', false);
        $this->get($this->url($offer).'?confirmed=1')->assertStatus(405);
        $this->post($this->url($offer))->assertSessionHasErrors(['confirmed'], errorBag: 'acceptance');
        $this->assertDatabaseCount('project_assignments', 0);
        $this->app->instance('env', 'local');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->post($this->url($offer), ['confirmed' => 1])->assertStatus(419);
        $token = str_repeat('b', 40);
        $this->withSession(['_token' => $token])->post($this->url($offer), ['confirmed' => 1, '_token' => $token])->assertRedirect();
        $this->assertDatabaseCount('project_assignments', 1);
    }

    public function test_persisted_client_role_and_ownership_are_rechecked(): void
    {
        $offer = $this->offer();
        $owner = $offer->project->client;
        $this->actingAs($owner);
        DB::table('users')->where('id', $owner->id)->update(['role' => User::ROLE_FREELANCER]);
        $this->post($this->url($offer), ['confirmed' => 1])->assertForbidden();
        DB::table('users')->where('id', $owner->id)->update(['role' => User::ROLE_CLIENT]);
        DB::table('projects')->where('id', $offer->project_id)->update(['client_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id]);
        $this->post($this->url($offer), ['confirmed' => 1])->assertForbidden();
        $this->assertDatabaseCount('project_assignments', 0);
    }

    #[DataProvider('ineligibleProjects')]
    public function test_ineligible_projects_are_refused_without_mutation(array $changes, bool $inactive = false): void
    {
        $offer = $this->offer();
        if ($inactive) {
            $offer->project->category->update(['is_active' => false]);
        } else {
            DB::table('projects')->where('id', $offer->project_id)->update($changes);
        }
        $before = $offer->project->fresh()->getAttributes();
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasErrors(errorBag: 'acceptance');
        $this->assertSame($before, $offer->project->fresh()->getAttributes());
        $this->assertSame(ProposalStatus::Submitted, $offer->fresh()->status);
        $this->assertDatabaseCount('project_assignments', 0);
    }

    #[DataProvider('invalidOffers')]
    public function test_invalid_saved_terms_cannot_be_replaced_by_request_fields(string $field, string $value): void
    {
        $offer = $this->offer();
        DB::table('proposals')->where('id', $offer->id)->update([$field => $value]);
        $before = $offer->fresh()->getAttributes();
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1, 'proposed_budget' => '100.00', 'proposed_deadline' => '2026-10-24'])
            ->assertSessionHasErrors([$field], errorBag: 'acceptance');
        $this->assertSame($before, $offer->fresh()->getAttributes());
        $this->assertDatabaseCount('project_assignments', 0);
        $this->assertSame(ProjectStatus::Open, $offer->project->fresh()->status);
        $this->get(route('client.projects.proposals.show', [$offer->project, $offer]))->assertOk()->assertSeeText('Proposal not accepted');
    }

    #[DataProvider('notSubmitted')]
    public function test_only_submitted_offers_may_be_selected(ProposalStatus $status): void
    {
        $offer = $this->offer(['status' => $status]);
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasErrors(['proposal'], errorBag: 'acceptance');
        $this->assertSame($status, $offer->fresh()->status);
        $this->assertDatabaseCount('project_assignments', 0);
    }

    #[DataProvider('nonFreelancerRoles')]
    public function test_the_saved_offer_owner_must_still_be_a_freelancer(string $role): void
    {
        $offer = $this->offer();
        $offer->freelancer->forceFill(['role' => $role])->save();
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasErrors(['proposal'], errorBag: 'acceptance');
        $this->assertDatabaseCount('project_assignments', 0);
    }

    public function test_historical_self_offer_is_refused(): void
    {
        $offer = $this->offer();
        DB::table('proposals')->where('id', $offer->id)->update(['freelancer_id' => $offer->project->client_id]);
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasErrors(['proposal'], errorBag: 'acceptance');
        $this->assertDatabaseCount('project_assignments', 0);
    }

    public function test_same_selection_retry_preserves_original_assignment_and_different_selection_is_refused(): void
    {
        $offer = $this->offer();
        $other = Proposal::factory()->create(['project_id' => $offer->project_id]);
        $this->actingAs($offer->project->client);
        $this->post($this->url($offer), ['confirmed' => 1])->assertSessionHasNoErrors();
        $before = ProjectAssignment::sole()->getAttributes();
        $projectBefore = $offer->project->fresh()->getAttributes();
        $offerBefore = $offer->fresh()->getAttributes();
        $otherBefore = $other->fresh()->getAttributes();
        $this->travel(10)->days();
        $offer->project->category->update(['is_active' => false]);
        $this->post($this->url($offer), ['confirmed' => 1])->assertSessionHasNoErrors()->assertSessionHas('proposal-acceptance', fn ($message) => str_contains($message, 'original assignment'));
        $this->post($this->url($other), ['confirmed' => 1])->assertSessionHasErrors(['proposal'], errorBag: 'acceptance');
        $this->assertDatabaseCount('project_assignments', 1);
        $this->assertSame($before, ProjectAssignment::sole()->getAttributes());
        $this->assertSame($projectBefore, $offer->project->fresh()->getAttributes());
        $this->assertSame($offerBefore, $offer->fresh()->getAttributes());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
    }

    public function test_a_failure_at_the_last_write_rolls_everything_back(): void
    {
        $offer = $this->offer();
        $other = Proposal::factory()->create(['project_id' => $offer->project_id]);
        $before = [$offer->fresh()->getAttributes(), $other->fresh()->getAttributes(), $offer->project->fresh()->getAttributes()];
        $this->actingAs($offer->project->client);
        Project::updating(function (): void {
            throw new RuntimeException('Simulated final write failure');
        });
        try {
            app(AcceptProposal::class)->accept($offer->project_id, $offer->id, true);
            $this->fail('Expected a failed project write.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated final write failure', $exception->getMessage());
        } finally {
            Project::flushEventListeners();
        }
        $this->assertSame($before, [$offer->fresh()->getAttributes(), $other->fresh()->getAttributes(), $offer->project->fresh()->getAttributes()]);
        $this->assertDatabaseCount('project_assignments', 0);
    }

    public function test_stale_submission_and_new_direct_submission_cannot_slip_in_after_acceptance(): void
    {
        $offer = $this->offer();
        $newFreelancer = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $this->actingAs($newFreelancer);
        $form = Volt::test('freelancer.proposals.create', ['project' => $offer->project])
            ->set('form.cover_letter', 'A new offer.')->set('form.proposed_budget', '1500.00')->set('form.proposed_deadline', '2026-10-24');
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->actingAs($newFreelancer);
        $form->call('submit')->assertHasErrors(['project']);
        $this->assertDatabaseCount('proposals', 1);
        // Also defend against an inconsistent legacy open status when an assignment exists.
        DB::table('projects')->where('id', $offer->project_id)->update(['status' => ProjectStatus::Open->value]);
        $this->expectException(ValidationException::class);
        app(SubmitProposal::class)->submit($offer->project_id, ['cover_letter' => 'A new offer.', 'proposed_budget' => '100.00', 'proposed_deadline' => '2026-10-24']);
    }

    public function test_sri_lankan_midnight_expires_an_offer_even_while_utc_date_is_unchanged(): void
    {
        $offer = $this->offer(['proposed_deadline' => '2026-10-21']);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 18:30:00', 'UTC'));
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasErrors(['proposed_deadline'], errorBag: 'acceptance');
        $this->assertDatabaseCount('project_assignments', 0);
    }

    #[DataProvider('validOffers')]
    public function test_valid_budget_and_date_boundaries_are_copied_exactly(string $budget, string $deadline): void
    {
        $offer = $this->offer(['proposed_budget' => $budget, 'proposed_deadline' => $deadline]);
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasNoErrors();
        $assignment = ProjectAssignment::sole();
        $this->assertSame($budget, $assignment->agreed_budget);
        $this->assertSame($deadline, $assignment->agreed_deadline->toDateString());
    }

    public function test_agreed_terms_remain_immutable_if_original_records_are_changed_by_trusted_code(): void
    {
        $offer = $this->offer();
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasNoErrors();
        $before = ProjectAssignment::sole()->getAttributes();
        DB::table('proposals')->where('id', $offer->id)->update(['proposed_budget' => '999.99', 'proposed_deadline' => '2026-10-22']);
        DB::table('projects')->where('id', $offer->project_id)->update(['budget' => '777.77', 'deadline' => '2026-10-23']);
        $this->travel(1)->days();
        $this->post($this->url($offer), ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame($before, ProjectAssignment::sole()->getAttributes());
    }

    public function test_eligibility_is_rechecked_after_a_simulated_change_during_account_locking(): void
    {
        $offer = $this->offer();
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($offer, &$changed): void {
            if (! $changed && str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'order by "id"')) {
                $changed = true;
                DB::table('projects')->where('id', $offer->project_id)->update(['status' => 'cancelled']);
            }
        });
        $this->actingAs($offer->project->client)->post($this->url($offer), ['confirmed' => 1])->assertSessionHasErrors(['project'], errorBag: 'acceptance');
        $this->assertTrue($changed);
        $this->assertDatabaseCount('project_assignments', 0);
    }

    public function test_unrelated_unique_errors_are_not_swallowed(): void
    {
        $offer = $this->offer();
        $this->actingAs($offer->project->client);
        $previous = new PDOException('UNIQUE constraint failed: unrelated.key');
        $previous->errorInfo = ['23000', 19, 'UNIQUE constraint failed: unrelated.key'];
        ProjectAssignment::creating(function () use ($previous): void {
            throw new UniqueConstraintViolationException('sqlite', 'insert', [], $previous);
        });
        try {
            app(AcceptProposal::class)->accept($offer->project_id, $offer->id, true);
            $this->fail('Expected unrelated constraint failure.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString('unrelated.key', $exception->getMessage());
        } finally {
            ProjectAssignment::flushEventListeners();
        }
        $this->assertDatabaseCount('project_assignments', 0);
        $this->assertSame(ProposalStatus::Submitted, $offer->fresh()->status);
    }

    public function test_a_simulated_assignment_unique_conflict_returns_the_original_selection(): void
    {
        $offer = $this->offer();
        $this->actingAs($offer->project->client);
        $inserted = false;
        // SQLite simulation: insert after the initial assignment lookup returned no rows.
        // This is not evidence of parallel MySQL locking behavior.
        DB::listen(function (QueryExecuted $query) use ($offer, &$inserted): void {
            if (! $inserted && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "project_assignments"')) {
                $inserted = true;
                DB::table('project_assignments')->insert([
                    'project_id' => $offer->project_id, 'proposal_id' => $offer->id, 'freelancer_id' => $offer->freelancer_id,
                    'agreed_budget' => '45000.25', 'agreed_deadline' => '2026-10-24', 'assigned_at' => '2026-10-20 11:59:59',
                    'created_at' => '2026-10-20 11:59:59', 'updated_at' => '2026-10-20 11:59:59',
                ]);
                DB::table('proposals')->where('id', $offer->id)->update(['status' => 'accepted']);
                DB::table('projects')->where('id', $offer->project_id)->update(['status' => 'in_progress']);
            }
        });
        $result = app(AcceptProposal::class)->accept($offer->project_id, $offer->id, true);
        $this->assertTrue($inserted);
        $this->assertFalse($result['created']);
        $this->assertDatabaseCount('project_assignments', 1);
        $this->assertSame('2026-10-20 11:59:59', $result['assignment']->getRawOriginal('assigned_at'));
        $this->assertSame('45000.25', $result['assignment']->agreed_budget);
        $this->assertSame(ProjectStatus::InProgress, $offer->project->fresh()->status);
    }

    private function offer(array $attributes = [], array $projectAttributes = []): Proposal
    {
        $project = Project::factory()->create(array_replace(['status' => ProjectStatus::Open, 'published_at' => '2026-10-20 11:00:00', 'deadline' => '2026-10-25', 'budget' => '50000.50'], $projectAttributes));

        return Proposal::factory()->create(array_replace(['project_id' => $project->id, 'proposed_budget' => '45000.25', 'proposed_deadline' => '2026-10-24'], $attributes));
    }

    private function url(Proposal $proposal): string
    {
        return route('client.projects.proposals.accept', [$proposal->project_id, $proposal]);
    }

    public static function wrongRoles(): array
    {
        return [[User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function nonFreelancerRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_ADMIN]];
    }

    public static function validOffers(): array
    {
        return [['1.00', '2026-10-21'], ['9999999999.99', '2026-10-25'], ['1234567890.01', '2026-10-24']];
    }

    public static function ineligibleProjects(): array
    {
        return [
            [['status' => 'draft']], [['status' => 'in_progress']], [['status' => 'completed']], [['status' => 'cancelled']],
            [['published_at' => null]], [['published_at' => '2026-10-20 12:00:01']], [[], true],
            [['deadline' => '2026-10-20']], [['deadline' => '2026-02-30']],
        ];
    }

    public static function invalidOffers(): array
    {
        return [
            ['proposed_budget', '0.99'], ['proposed_budget', '-1.00'], ['proposed_budget', '10000000000.00'],
            ['proposed_budget', '10.001'], ['proposed_budget', 'invalid'],
            ['proposed_deadline', '2026-10-20'], ['proposed_deadline', '2026-10-26'], ['proposed_deadline', '2026-02-30'],
            ['proposed_deadline', 'not-a-date'],
        ];
    }

    public static function notSubmitted(): array
    {
        return [[ProposalStatus::Accepted], [ProposalStatus::Rejected], [ProposalStatus::Withdrawn]];
    }
}
