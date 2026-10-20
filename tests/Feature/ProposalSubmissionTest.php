<?php

namespace Tests\Feature;

use App\Actions\Proposals\SubmitProposal;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProposalSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    }

    public function test_guests_cannot_open_or_invoke_submission(): void
    {
        $project = $this->project();
        $this->get(route('freelancer.proposals.create', $project))->assertRedirect(route('login'));
        Volt::test('freelancer.proposals.create', ['project' => $project])->assertForbidden();
        $this->expectException(HttpException::class);
        app(SubmitProposal::class)->submit($project->id, $this->details());
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_open_or_call_submission(string $role): void
    {
        $project = $this->project();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route('freelancer.proposals.create', $project))->assertForbidden();
        Volt::test('freelancer.proposals.create', ['project' => $project])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(SubmitProposal::class)->submit($project->id, $this->details());
    }

    public function test_gets_never_create_proposals_and_the_form_shows_the_advertised_budget(): void
    {
        $project = $this->project(['budget' => '25000.50']);
        $this->freelancer();
        $before = $project->fresh()->getAttributes();
        $this->get(route('freelancer.projects.show', $project))->assertOk()->assertSee(route('freelancer.proposals.create', $project));
        $this->get(route('freelancer.proposals.create', $project))->assertOk()->assertSeeText('Advertised fixed budget')
            ->assertSeeText('LKR 25,000.50')->assertSeeText('Your proposed fixed budget (LKR)')
            ->assertSeeText('Submitting a proposal does not assign the project or guarantee acceptance.');
        $this->get(route('freelancer.proposals.create', ['project' => $project->id, 'submit' => 1, 'status' => 'submitted']))->assertOk();
        $this->assertDatabaseCount('proposals', 0);
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    public function test_a_private_freelancer_can_submit_a_different_offer_with_explicit_server_metadata(): void
    {
        $project = $this->project(['budget' => '25000.50']);
        $user = $this->freelancer();
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => false]);
        $userBefore = $user->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->getAttributes();
        $projectBefore = $project->fresh()->getAttributes();
        $component = $this->form($project, ['cover_letter' => "  My approach.\nA clear plan.  ", 'proposed_budget' => '31000.5'])
            ->call('submit')->assertHasNoErrors();
        $proposal = Proposal::sole();
        $component->assertRedirect(route('freelancer.proposals.show', $proposal));

        $this->assertSame($project->id, $proposal->project_id);
        $this->assertSame($user->id, $proposal->freelancer_id);
        $this->assertSame("My approach.\nA clear plan.", $proposal->cover_letter);
        $this->assertSame('31000.50', $proposal->proposed_budget);
        $this->assertSame(ProposalStatus::Submitted, $proposal->status);
        $this->assertSame('2026-10-20 12:00:00', $proposal->getRawOriginal('submitted_at'));
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertSame($projectBefore, $project->fresh()->getAttributes());
        $this->get(route('freelancer.proposals.show', $proposal))->assertOk()->assertSeeText('Proposal submitted.')
            ->assertSeeText('LKR 31,000.50')->assertSeeText('LKR 25,000.50')->assertSeeText('20 October 2026, 5:30 pm (Asia/Colombo)');
    }

    public function test_a_freelancer_without_a_profile_can_submit_and_extra_fields_are_ignored(): void
    {
        $project = $this->project();
        $user = $this->freelancer();
        $other = Proposal::factory()->create();
        $result = app(SubmitProposal::class)->submit($project->id, $this->details() + [
            'project_id' => $other->project_id, 'projectId' => $other->project_id, 'freelancer_id' => $other->freelancer_id,
            'status' => 'accepted', 'submitted_at' => '2000-01-01', 'role' => 'admin', 'is_public' => true,
        ]);
        $proposal = $result['proposal']->fresh();
        $this->assertTrue($result['created']);
        $this->assertSame($project->id, $proposal->project_id);
        $this->assertSame($user->id, $proposal->freelancer_id);
        $this->assertSame(ProposalStatus::Submitted, $proposal->status);
        $this->assertSame('2026-10-20 12:00:00', $proposal->getRawOriginal('submitted_at'));
        $this->assertNull($user->fresh()->freelancerProfile);
        $this->assertSame(User::ROLE_FREELANCER, $user->fresh()->role);
    }

    public function test_project_identity_is_locked(): void
    {
        $project = $this->project();
        $this->freelancer();
        $form = Volt::test('freelancer.proposals.create', ['project' => $project]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $form->set('projectId', $this->project()->id);
    }

    #[DataProvider('forgedFields')]
    public function test_protected_fields_cannot_be_injected_into_the_form(string $field): void
    {
        $project = $this->project();
        $this->freelancer();
        $this->form($project)->set('form.'.$field, '1')->assertForbidden();
        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_historical_ownership_with_a_freelancer_role_cannot_self_apply(): void
    {
        $project = $this->project();
        $owner = $project->client;
        $owner->role = User::ROLE_FREELANCER;
        $owner->save();
        $this->actingAs($owner);
        $this->get(route('freelancer.projects.show', $project))->assertOk()->assertSeeText('You cannot submit a proposal to your own project.')
            ->assertDontSee(route('freelancer.proposals.create', $project));
        $this->get(route('freelancer.proposals.create', $project))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(SubmitProposal::class)->submit($project->id, $this->details());
    }

    public function test_submission_rechecks_persisted_role_and_self_ownership_after_the_page_opened(): void
    {
        $project = $this->project();
        $user = $this->freelancer();
        $form = $this->form($project);
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_CLIENT]);
        $form->call('submit')->assertForbidden();
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_FREELANCER]);
        $form = $this->form($project);
        DB::table('projects')->where('id', $project->id)->update(['client_id' => $user->id]);
        $form->call('submit')->assertForbidden();
        $this->assertDatabaseCount('proposals', 0);
    }

    #[DataProvider('invalidOffers')]
    public function test_invalid_offers_leave_storage_unchanged_and_retain_entered_values(string $field, mixed $invalid): void
    {
        $project = $this->project();
        $this->freelancer();
        $before = $project->fresh()->getAttributes();
        $component = $this->form($project, [$field => $invalid])->call('submit')->assertHasErrors(['form.'.$field]);
        if ($field !== 'cover_letter') {
            $component->assertSet('form.cover_letter', 'My considered approach.');
        }
        $this->assertDatabaseCount('proposals', 0);
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    #[DataProvider('validBudgets')]
    public function test_lkr_boundary_offers_are_saved_exactly(string $budget, string $expected): void
    {
        $project = $this->project();
        $this->freelancer();
        $this->form($project, ['proposed_budget' => $budget])->call('submit')->assertHasNoErrors();
        $this->assertSame($expected, Proposal::sole()->proposed_budget);
    }

    public function test_maximum_cover_letter_and_both_completion_date_boundaries_are_allowed(): void
    {
        $project = $this->project(['deadline' => '2026-10-23']);
        foreach (['2026-10-21', '2026-10-23'] as $deadline) {
            $this->freelancer();
            $this->form($project, ['cover_letter' => str_repeat('A', 5000), 'proposed_deadline' => $deadline])->call('submit')->assertHasNoErrors();
        }
        $this->assertDatabaseCount('proposals', 2);
    }

    #[DataProvider('ineligibleChanges')]
    public function test_projects_becoming_ineligible_after_opening_are_rejected(array $changes, bool $inactive = false): void
    {
        $project = $this->project();
        $this->freelancer();
        $form = $this->form($project);
        if ($inactive) {
            $project->category->update(['is_active' => false]);
        } else {
            DB::table('projects')->where('id', $project->id)->update($changes);
        }
        $form->call('submit')->assertHasErrors(['project'])->assertSet('form.cover_letter', 'My considered approach.');
        $this->assertDatabaseCount('proposals', 0);
        $this->get(route('freelancer.proposals.create', $project))->assertNotFound();
    }

    public function test_no_completion_window_is_explained_and_enforced_server_side(): void
    {
        $project = $this->project(['deadline' => '2026-10-20']);
        $this->freelancer();
        $this->get(route('freelancer.projects.show', $project))->assertOk()->assertSeeText('There is no valid completion date left.')
            ->assertDontSee(route('freelancer.proposals.create', $project));
        $this->get(route('freelancer.proposals.create', $project))->assertOk()->assertSeeText('There is no valid completion date left.');
        $this->form($project)->call('submit')->assertHasErrors(['project']);
        $this->assertDatabaseCount('proposals', 0);
    }

    #[DataProvider('colomboTimes')]
    public function test_completion_rules_follow_colombo_midnight(string $utc, bool $valid): void
    {
        $project = $this->project(['deadline' => '2026-10-22']);
        $this->freelancer();
        $form = $this->form($project, ['proposed_deadline' => '2026-10-21']);
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $response = $form->call('submit');
        $valid ? $response->assertHasNoErrors() : $response->assertHasErrors(['form.proposed_deadline']);
        $this->assertDatabaseCount('proposals', $valid ? 1 : 0);
    }

    public function test_active_category_is_rechecked_after_the_discovery_query(): void
    {
        $project = $this->project();
        $this->freelancer();
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($project, &$changed): void {
            if (! $changed && str_contains($query->sql, 'exists(select') && str_contains($query->sql, 'projects')) {
                $changed = true;
                $project->category()->update(['is_active' => false]);
            }
        });
        try {
            app(SubmitProposal::class)->submit($project->id, $this->details());
            $this->fail('The category must remain active.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project', $exception->errors());
        }
        $this->assertTrue($changed);
        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_repeated_submissions_preserve_every_original_field_even_after_the_project_closes(): void
    {
        $project = $this->project();
        $this->freelancer();
        $first = app(SubmitProposal::class)->submit($project->id, $this->details());
        $before = $first['proposal']->fresh()->getAttributes();
        $this->travel(1)->hours();
        $this->form($project, ['cover_letter' => 'Replacement', 'proposed_budget' => '1.00'])->call('submit')
            ->assertRedirect(route('freelancer.proposals.show', $first['proposal']));
        $this->get(route('freelancer.proposals.show', $first['proposal']))->assertSeeText('Your proposal is already on record.');
        $project->status = ProjectStatus::InProgress;
        $project->save();
        $repeat = app(SubmitProposal::class)->submit($project->id, ['cover_letter' => 'Replacement']);
        $this->assertFalse($repeat['created']);
        $this->assertSame($before, $repeat['proposal']->fresh()->getAttributes());
        $this->assertDatabaseCount('proposals', 1);
    }

    public function test_a_simulated_interleaved_duplicate_returns_the_first_offer_without_overwriting_it(): void
    {
        $project = $this->project();
        $user = $this->freelancer();
        $winner = null;
        DB::listen(function (QueryExecuted $query) use ($project, $user, &$winner): void {
            if (! $winner && str_starts_with($query->sql, 'select * from "categories"')) {
                // SQLite interleaving inside one transaction; not parallel MySQL execution.
                $winner = true;
                $winner = Proposal::factory()->create([
                    'project_id' => $project->id, 'freelancer_id' => $user->id, 'cover_letter' => 'First submitted offer.',
                    'proposed_budget' => '12345.67', 'submitted_at' => '2026-10-20 11:59:00',
                ]);
            }
        });
        $result = app(SubmitProposal::class)->submit($project->id, $this->details());
        $this->assertInstanceOf(Proposal::class, $winner);
        $this->assertFalse($result['created']);
        $this->assertTrue($winner->is($result['proposal']));
        $this->assertSame('First submitted offer.', $result['proposal']->cover_letter);
        $this->assertSame('2026-10-20 11:59:00', $result['proposal']->getRawOriginal('submitted_at'));
        $this->assertDatabaseCount('proposals', 1);
    }

    public function test_unrelated_unique_conflicts_are_not_swallowed(): void
    {
        $project = $this->project();
        $this->freelancer();
        $other = Proposal::factory()->create();
        Proposal::creating(function (Proposal $proposal) use ($other): void {
            $proposal->id = $other->id;
        });
        $this->expectException(UniqueConstraintViolationException::class);
        app(SubmitProposal::class)->submit($project->id, $this->details());
    }

    public function test_a_simulated_mysql_named_pair_conflict_returns_the_existing_offer(): void
    {
        $project = $this->project();
        $user = $this->freelancer();
        $winner = null;
        DB::listen(function (QueryExecuted $query) use ($project, $user, &$winner): void {
            if (! $winner && str_starts_with($query->sql, 'select * from "categories"')) {
                $winner = true;
                $winner = Proposal::factory()->create(['project_id' => $project->id, 'freelancer_id' => $user->id, 'submitted_at' => '2026-10-20 11:59:00']);
                // MySQL-shaped conflict on SQLite; not a real parallel MySQL race.
                Proposal::creating(function (): void {
                    $cause = new PDOException('Duplicate entry');
                    $cause->errorInfo = ['23000', 1062, "Duplicate entry for key 'proposals.proposals_project_id_freelancer_id_unique'"];
                    throw new UniqueConstraintViolationException('mysql', 'insert into proposals', [], $cause);
                });
            }
        });
        $result = app(SubmitProposal::class)->submit($project->id, $this->details());
        $this->assertInstanceOf(Proposal::class, $winner);
        $this->assertFalse($result['created']);
        $this->assertTrue($result['proposal']->is($winner));
        $this->assertSame('2026-10-20 11:59:00', $result['proposal']->getRawOriginal('submitted_at'));
        $this->assertDatabaseCount('proposals', 1);
    }

    public function test_unrelated_database_errors_are_not_swallowed(): void
    {
        $project = $this->project();
        $this->freelancer();
        Proposal::creating(function (Proposal $proposal): void {
            $proposal->cover_letter = null;
        });
        $this->expectException(QueryException::class);
        app(SubmitProposal::class)->submit($project->id, $this->details());
    }

    public function test_a_cancelled_model_save_is_reported_and_does_not_create_a_proposal(): void
    {
        $project = $this->project();
        $this->freelancer();
        Proposal::creating(fn () => false);
        try {
            app(SubmitProposal::class)->submit($project->id, $this->details());
            $this->fail('A cancelled save cannot report success.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The proposal could not be submitted.', $exception->getMessage());
        }
        $this->assertDatabaseCount('proposals', 0);
    }

    private function project(array $attributes = []): Project
    {
        return Project::factory()->create(array_replace(['status' => ProjectStatus::Open, 'published_at' => '2026-10-20 10:00:00', 'deadline' => '2026-10-23'], $attributes));
    }

    private function freelancer(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $this->actingAs($user);

        return $user;
    }

    private function details(): array
    {
        return ['cover_letter' => 'My considered approach.', 'proposed_budget' => '35000.50', 'proposed_deadline' => '2026-10-22'];
    }

    private function form(Project $project, array $overrides = []): Testable
    {
        $form = Volt::test('freelancer.proposals.create', ['project' => $project]);
        foreach (array_replace($this->details(), $overrides) as $field => $value) {
            $form->set('form.'.$field, $value);
        }

        return $form;
    }

    public static function otherRoles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'admin' => [User::ROLE_ADMIN]];
    }

    public static function forgedFields(): array
    {
        return array_map(fn ($field) => [$field], ['project_id', 'freelancer_id', 'status', 'submitted_at', 'role', 'is_public']);
    }

    public static function invalidOffers(): array
    {
        return [
            'empty cover' => ['cover_letter', ''], 'ASCII whitespace' => ['cover_letter', " \t\n "],
            'Unicode whitespace' => ['cover_letter', "\u{00a0}\u{2003}"], 'long cover' => ['cover_letter', str_repeat('A', 5001)],
            'missing budget' => ['proposed_budget', ''], 'zero budget' => ['proposed_budget', '0'],
            'below minimum' => ['proposed_budget', '0.99'], 'negative budget' => ['proposed_budget', '-1'],
            'excess precision' => ['proposed_budget', '1.001'], 'exponent' => ['proposed_budget', '1e3'],
            'over maximum' => ['proposed_budget', '10000000000'], 'comma' => ['proposed_budget', '1,000'],
            'missing date' => ['proposed_deadline', ''], 'today' => ['proposed_deadline', '2026-10-20'],
            'past date' => ['proposed_deadline', '2026-10-19'], 'after deadline' => ['proposed_deadline', '2026-10-24'],
            'invalid date' => ['proposed_deadline', '2027-02-29'], 'date with time' => ['proposed_deadline', '2026-10-22 12:00:00'],
        ];
    }

    public static function validBudgets(): array
    {
        return [['1', '1.00'], ['0'.'1.01', '1.01'], ['9999999999.99', '9999999999.99']];
    }

    public static function ineligibleChanges(): array
    {
        return [
            'draft' => [['status' => 'draft']], 'in progress' => [['status' => 'in_progress']],
            'completed' => [['status' => 'completed']], 'cancelled' => [['status' => 'cancelled']],
            'unpublished' => [['published_at' => null]], 'future published' => [['published_at' => '2026-10-20 12:00:01']],
            'expired' => [['deadline' => '2026-10-19']], 'inactive category' => [[], true],
        ];
    }

    public static function colomboTimes(): array
    {
        return [['2026-10-20 18:29:59', true], ['2026-10-20 18:30:00', false]];
    }
}
