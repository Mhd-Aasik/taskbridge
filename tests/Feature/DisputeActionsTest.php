<?php

namespace Tests\Feature;

use App\Actions\Disputes\OpenDispute;
use App\Actions\Disputes\ResolveDispute;
use App\Actions\Disputes\SubmitDisputeStatement;
use App\Enums\DisputeDecision;
use App\Enums\DisputeStatementKind;
use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\DisputeStatement;
use App\Models\ProjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DisputeActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 18:45:00', 'UTC'));
    }

    #[DataProvider('participants')]
    public function test_participants_open_with_trusted_identity_and_immutable_retry_history(string $actor): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $user = $actor === 'client' ? $assignment->project->client : $assignment->freelancer;
        $this->actingAs($user);
        $token = (string) Str::uuid();
        $input = ['title' => '  Clarify the outcome  ', 'reason' => "  Plain text\n<script>unsafe</script>  ", 'confirmed' => true, 'project_assignment_id' => 999999, 'opened_by' => 999999, 'opened_at' => '2000-01-01', 'status' => 'resolved'];
        $case = app(OpenDispute::class)->open($assignment->project_id, $input, $token);
        $before = $case->fresh()->getAttributes();
        $this->assertSame($assignment->id, $case->project_assignment_id);
        $this->assertSame($user->id, $case->opened_by);
        $this->assertSame('Clarify the outcome', $case->title);
        $this->assertSame("Plain text\n<script>unsafe</script>", $case->reason);
        $this->assertSame('2026-10-27 18:45:00', $case->opened_at->format('Y-m-d H:i:s'));
        $this->travel(1)->hours();
        $retry = app(OpenDispute::class)->open($assignment->project_id, $input, strtoupper($token));
        $this->assertTrue($retry->is($case));
        $this->assertSame($before, $case->fresh()->getAttributes());
        $this->assertDatabaseCount('dispute_cases', 1);
        $this->assertDatabaseCount('active_disputes', 1);
        $this->expectException(ValidationException::class);
        app(OpenDispute::class)->open($assignment->project_id, array_merge($input, ['reason' => 'Changed original reason']), $token);
    }

    public function test_competing_openings_return_existing_case_then_allow_new_case_after_resolution(): void
    {
        // Sequential competing requests simulate the serialized assignment-lock interleaving.
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $token = (string) Str::uuid();
        $first = $this->open($assignment, $token);
        $this->actingAs($assignment->freelancer);
        $competing = $this->open($assignment);
        $this->assertTrue($competing->is($first));
        $this->assertDatabaseCount('dispute_cases', 1);
        $this->actingAs(User::factory()->state(['role' => User::ROLE_ADMIN])->create());
        $this->resolve($first);
        $this->actingAs($assignment->project->client);
        $this->assertTrue($this->open($assignment, $token)->is($first));
        $later = $this->open($assignment);
        $this->assertFalse($later->is($first));
        $this->assertSame('resolved', $first->fresh()->state());
        $this->assertSame('open', $later->fresh()->state());
        $this->assertDatabaseCount('active_disputes', 1);
        // A retry must use the original deciding Admin, and must not close the later case.
        $this->actingAs($first->fresh()->resolution->decider);
        $this->resolve($first);
        $this->assertSame($later->id, $assignment->activeDispute()->sole()->dispute_case_id);
    }

    #[DataProvider('unauthorizedActors')]
    public function test_unrelated_accounts_and_wrong_roles_cannot_open_or_write(string $kind): void
    {
        $case = DisputeCase::factory()->create();
        $user = match ($kind) {
            'other_client' => User::factory()->state(['role' => User::ROLE_CLIENT])->create(),
            'other_freelancer' => User::factory()->state(['role' => User::ROLE_FREELANCER])->create(),
            'admin' => User::factory()->state(['role' => User::ROLE_ADMIN])->create(),
            'changed_client' => $case->assignment->project->client,
            'changed_freelancer' => $case->assignment->freelancer,
        };
        $this->actingAs($user);
        if (str_starts_with($kind, 'changed')) {
            DB::table('users')->where('id', $user->id)->update(['role' => $user->role === User::ROLE_CLIENT ? User::ROLE_FREELANCER : User::ROLE_CLIENT]);
        }
        $this->expectException(AuthorizationException::class);
        $this->open($case->assignment);
    }

    #[DataProvider('statementActors')]
    public function test_authorized_statements_are_append_only_server_attributed_and_retryable(string $actor): void
    {
        $case = DisputeCase::factory()->create();
        $user = match ($actor) {
            'client' => $case->assignment->project->client,
            'freelancer' => $case->assignment->freelancer,
            'admin' => User::factory()->state(['role' => User::ROLE_ADMIN])->create(),
        };
        $this->actingAs($user);
        $token = (string) Str::uuid();
        $input = ['body' => '  Clarify the submitted evidence.  ', 'dispute_case_id' => 99999, 'submitted_by' => 99999, 'submitted_at' => '2000-01-01', 'kind' => 'forged'];
        $statement = app(SubmitDisputeStatement::class)->submit($case->id, $input, $token);
        $before = $statement->fresh()->getAttributes();
        $this->assertSame($user->id, $statement->submitted_by);
        $this->assertSame($case->id, $statement->dispute_case_id);
        $this->assertSame($actor === 'admin' ? DisputeStatementKind::Clarification : DisputeStatementKind::Statement, $statement->kind);
        $this->assertSame('Clarify the submitted evidence.', $statement->body);
        $this->assertSame('2026-10-27 18:45:00', $statement->submitted_at->format('Y-m-d H:i:s'));
        $this->actingAs(User::factory()->state(['role' => User::ROLE_ADMIN])->create());
        $this->resolve($case);
        $this->actingAs($user);
        $this->travel(1)->hours();
        $this->assertTrue(app(SubmitDisputeStatement::class)->submit($case->id, $input, $token)->is($statement));
        $this->assertSame($before, $statement->fresh()->getAttributes());
        $this->expectException(ValidationException::class);
        app(SubmitDisputeStatement::class)->submit($case->id, $input, (string) Str::uuid());
    }

    public function test_statement_identifier_cannot_change_actor_body_or_case(): void
    {
        $case = DisputeCase::factory()->create();
        $first = DisputeStatement::factory()->create(['dispute_case_id' => $case->id]);
        $this->actingAs($case->assignment->freelancer);
        try {
            app(SubmitDisputeStatement::class)->submit($case->id, ['body' => $first->body], $first->submission_id);
            $this->fail('Another actor must not reuse a statement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('statement', $exception->errors());
        }
        $other = DisputeCase::factory()->create();
        $this->actingAs($other->opener);
        $this->expectException(ValidationException::class);
        app(SubmitDisputeStatement::class)->submit($other->id, ['body' => $first->body], $first->submission_id);
    }

    #[DataProvider('participants')]
    public function test_unrelated_participants_cannot_append_to_another_assignment(string $role): void
    {
        $case = DisputeCase::factory()->create();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->expectException(AuthorizationException::class);
        app(SubmitDisputeStatement::class)->submit($case->id, ['body' => 'Unrelated request'], (string) Str::uuid());
    }

    public function test_conflicting_resolution_decision_and_other_admin_retry_are_refused(): void
    {
        $case = DisputeCase::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);
        $original = $this->resolve($case)->fresh();
        foreach (['decision', 'actor'] as $conflict) {
            if ($conflict === 'actor') {
                $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
            }
            try {
                app(ResolveDispute::class)->resolve($case->id, ['decision' => $conflict === 'decision' ? 'dismissed' : 'resolved', 'rationale' => $original->rationale, 'confirmed' => true]);
                $this->fail('The original decision and actor must be preserved.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('resolution', $exception->errors());
            }
        }
        $this->assertSame($original->getAttributes(), $original->fresh()->getAttributes());
    }

    #[DataProvider('decisions')]
    public function test_admin_resolution_is_atomic_and_exact_retry_preserves_all_metadata(string $decision): void
    {
        $case = DisputeCase::factory()->create();
        $before = $case->fresh()->getAttributes();
        $admin = User::factory()->state(['role' => User::ROLE_ADMIN])->create();
        $this->actingAs($admin);
        $input = ['decision' => $decision, 'rationale' => '  Reviewed the current evidence.  ', 'confirmed' => true, 'decided_by' => 99999, 'decided_at' => '2000-01-01', 'dispute_case_id' => 99999];
        $resolution = app(ResolveDispute::class)->resolve($case->id, $input);
        $history = $resolution->fresh()->getAttributes();
        $this->assertSame($admin->id, $resolution->decided_by);
        $this->assertSame($case->id, $resolution->dispute_case_id);
        $this->assertSame(DisputeDecision::from($decision), $resolution->decision);
        $this->assertSame('Reviewed the current evidence.', $resolution->rationale);
        $this->assertSame('2026-10-27 18:45:00', $resolution->decided_at->format('Y-m-d H:i:s'));
        $this->assertSame($decision, $case->fresh()->state());
        $this->assertDatabaseCount('active_disputes', 0);
        $this->travel(1)->hours();
        $this->assertTrue(app(ResolveDispute::class)->resolve($case->id, $input)->is($resolution));
        $this->assertSame($history, $resolution->fresh()->getAttributes());
        $this->assertSame($before, $case->fresh()->getAttributes());
        $this->expectException(ValidationException::class);
        app(ResolveDispute::class)->resolve($case->id, array_merge($input, ['rationale' => 'Conflicting later rationale']));
    }

    #[DataProvider('resolutionDenials')]
    public function test_only_current_admin_without_conflict_of_interest_can_resolve(string $actor): void
    {
        $case = DisputeCase::factory()->create();
        $user = match ($actor) {
            'client', 'former_client' => $case->assignment->project->client,
            'freelancer', 'former_freelancer' => $case->assignment->freelancer,
            default => User::factory()->state(['role' => User::ROLE_ADMIN])->create(),
        };
        if ($actor === 'former_opener') {
            DB::table('dispute_cases')->where('id', $case->id)->update(['opened_by' => $user->id]);
        }
        $this->actingAs($user);
        if (str_starts_with($actor, 'former_')) {
            DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_ADMIN]);
        } elseif ($actor === 'stale_admin') {
            DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_CLIENT]);
        }
        $this->expectException(AuthorizationException::class);
        $this->resolve($case);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_preserves_records(string $kind, string $field, mixed $value): void
    {
        $case = DisputeCase::factory()->create();
        $this->actingAs($kind === 'resolution' ? User::factory()->state(['role' => User::ROLE_ADMIN])->create() : $case->opener);
        try {
            match ($kind) {
                'opening' => app(OpenDispute::class)->open($case->assignment->project_id, array_merge(['title' => 'Review outcome', 'reason' => 'Meaningful reason', 'confirmed' => true], [$field => $value]), (string) Str::uuid()),
                'statement' => app(SubmitDisputeStatement::class)->submit($case->id, [$field => $value], (string) Str::uuid()),
                'resolution' => app(ResolveDispute::class)->resolve($case->id, array_merge(['decision' => 'resolved', 'rationale' => 'Reviewed evidence', 'confirmed' => true], [$field => $value])),
            };
            $this->fail('Invalid input must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('dispute_cases', 1);
        $this->assertDatabaseCount('dispute_statements', 0);
        $this->assertDatabaseCount('dispute_resolutions', 0);
        $this->assertDatabaseCount('active_disputes', 1);
    }

    public function test_maximum_lengths_are_accepted_and_cross_assignment_opening_token_is_refused(): void
    {
        $case = DisputeCase::factory()->create();
        $this->actingAs($case->opener);
        app(SubmitDisputeStatement::class)->submit($case->id, ['body' => str_repeat('S', 5000)], (string) Str::uuid());
        $this->actingAs(User::factory()->state(['role' => User::ROLE_ADMIN])->create());
        app(ResolveDispute::class)->resolve($case->id, ['decision' => 'dismissed', 'rationale' => str_repeat('R', 5000), 'confirmed' => true]);
        $other = ProjectAssignment::factory()->create();
        $this->actingAs($other->project->client);
        $created = app(OpenDispute::class)->open($other->project_id, ['title' => str_repeat('T', 150), 'reason' => str_repeat('R', 5000), 'confirmed' => true], (string) Str::uuid());
        $this->assertSame(150, strlen($created->title));
        $this->expectException(ValidationException::class);
        $this->open($other, $case->submission_id);
    }

    private function open(ProjectAssignment $assignment, ?string $token = null): DisputeCase
    {
        return app(OpenDispute::class)->open($assignment->project_id, ['title' => 'Review the outcome', 'reason' => 'Please review the agreed outcome.', 'confirmed' => true], $token ?? (string) Str::uuid());
    }

    private function resolve(DisputeCase $case): DisputeResolution
    {
        return app(ResolveDispute::class)->resolve($case->id, ['decision' => 'resolved', 'rationale' => 'Reviewed the evidence and clarified the outcome.', 'confirmed' => true]);
    }

    public static function participants(): array
    {
        return [['client'], ['freelancer']];
    }

    public static function statementActors(): array
    {
        return [['client'], ['freelancer'], ['admin']];
    }

    public static function unauthorizedActors(): array
    {
        return array_map(fn ($actor) => [$actor], ['other_client', 'other_freelancer', 'admin', 'changed_client', 'changed_freelancer']);
    }

    public static function decisions(): array
    {
        return [['resolved'], ['dismissed']];
    }

    public static function resolutionDenials(): array
    {
        return array_map(fn ($actor) => [$actor], ['client', 'freelancer', 'former_client', 'former_freelancer', 'former_opener', 'stale_admin']);
    }

    public static function invalidInputs(): array
    {
        $cases = [];
        foreach (['opening' => ['title' => 150, 'reason' => 5000], 'statement' => ['body' => 5000], 'resolution' => ['rationale' => 5000]] as $kind => $fields) {
            foreach ($fields as $field => $limit) {
                foreach (['', " \n\t ", "\u{200B}", str_repeat('A', $limit + 1), ['invalid']] as $value) {
                    $cases[] = [$kind, $field, $value];
                }
            }
        }
        $cases[] = ['opening', 'confirmed', false];
        $cases[] = ['resolution', 'confirmed', false];
        $cases[] = ['resolution', 'decision', 'refunded'];

        return $cases;
    }
}
