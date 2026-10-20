<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_defaults_and_relationships_distinguish_authored_proposals_from_owned_projects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $proposal = Proposal::factory()->create()->refresh();
        $project = $proposal->project;
        $freelancer = $proposal->freelancer;
        $client = $project->client;

        $this->assertSame(ProposalStatus::Submitted, $proposal->status);
        $this->assertSame(ProposalStatus::Submitted, (new Proposal)->status);
        $this->assertSame(User::ROLE_FREELANCER, $freelancer->role);
        $this->assertSame(User::ROLE_CLIENT, $client->role);
        $this->assertSame(ProjectStatus::Open, $project->status);
        $this->assertNotNull($project->published_at);
        $this->assertTrue($project->proposals->sole()->is($proposal));
        $this->assertTrue($freelancer->submittedProposals->sole()->is($proposal));
        $this->assertTrue($client->submittedProposals->isEmpty());
        $this->assertTrue($freelancer->projects->isEmpty());
        $this->assertTrue($client->projects->sole()->is($project));
        $this->assertSame($project->id, $proposal->project_id);
        $this->assertSame($freelancer->id, $proposal->freelancer_id);
        $this->assertSame('2026-10-20 12:00:00', $proposal->getRawOriginal('submitted_at'));
        $this->assertNotNull($proposal->created_at);
        $this->assertNotNull($proposal->updated_at);
    }

    public function test_relationships_allow_trusted_server_side_ownership_assignment(): void
    {
        $project = Project::factory()->create();
        $freelancer = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $proposal = new Proposal([
            'cover_letter' => 'My approach to this project.', 'proposed_budget' => '35000.50', 'proposed_deadline' => '2027-01-31',
        ]);
        $proposal->freelancer()->associate($freelancer);
        $proposal->submitted_at = CarbonImmutable::now('UTC');
        $project->proposals()->save($proposal);

        $this->assertTrue($proposal->fresh()->project->is($project));
        $this->assertTrue($proposal->fresh()->freelancer->is($freelancer));
        $this->assertTrue($freelancer->submittedProposals->sole()->is($proposal));
    }

    public function test_database_default_status_applies_without_eloquent(): void
    {
        $attributes = Proposal::factory()->create()->getAttributes();
        unset($attributes['id'], $attributes['status']);
        $attributes['freelancer_id'] = User::factory()->create(['role' => User::ROLE_FREELANCER])->id;
        $id = DB::table('proposals')->insertGetId($attributes);

        $this->assertSame(ProposalStatus::Submitted, Proposal::findOrFail($id)->status);
    }

    #[DataProvider('budgets')]
    public function test_proposed_budget_is_a_decimal_string(string $input, string $expected): void
    {
        $proposal = Proposal::factory()->create(['proposed_budget' => $input])->refresh();

        $this->assertSame($expected, $proposal->proposed_budget);
        $this->assertSame($expected, $proposal->toArray()['proposed_budget']);
    }

    public function test_offered_completion_date_is_a_calendar_date_and_cover_letter_is_plain_text(): void
    {
        $letter = "My approach.\n<literal> text & punctuation, including <script> as plain text.";
        $proposal = Proposal::factory()->create(['cover_letter' => $letter, 'proposed_deadline' => '2028-02-29'])->refresh();

        $this->assertInstanceOf(CarbonImmutable::class, $proposal->proposed_deadline);
        $this->assertSame('2028-02-29', $proposal->proposed_deadline->toDateString());
        $this->assertSame('2028-02-29', $proposal->toArray()['proposed_deadline']);
        $this->assertSame($letter, $proposal->cover_letter);
        $this->assertSame($letter, DB::table('proposals')->where('id', $proposal->id)->value('cover_letter'));
    }

    #[DataProvider('statuses')]
    public function test_status_is_cast_to_a_backed_enum_without_adding_a_transition_api(ProposalStatus $status): void
    {
        $proposal = Proposal::factory()->create();
        // Trusted assignment exercises storage only; no status action is exposed.
        $proposal->status = $status;
        $proposal->save();

        $this->assertSame($status, $proposal->fresh()->status);
        $this->assertSame($status->value, $proposal->fresh()->toArray()['status']);
        $this->assertTrue($proposal->freelancer->submittedProposals->sole()->is($proposal));
    }

    #[DataProvider('submissionTimes')]
    public function test_submission_timestamps_are_normalised_to_utc_and_cast_immutably(string $timestamp): void
    {
        $proposal = Proposal::factory()->create(['submitted_at' => $timestamp])->refresh();

        $this->assertInstanceOf(CarbonImmutable::class, $proposal->submitted_at);
        $this->assertSame('UTC', $proposal->submitted_at->timezoneName);
        $this->assertSame('2026-10-20 12:00:00', $proposal->getRawOriginal('submitted_at'));
        $this->assertSame('2026-10-20T12:00:00.000000Z', $proposal->toArray()['submitted_at']);
    }

    public function test_trusted_datetime_objects_are_converted_to_utc_without_changing_the_input(): void
    {
        $timestamp = new DateTimeImmutable('2026-10-20T17:30:00+05:30');
        $proposal = Proposal::factory()->create(['submitted_at' => $timestamp])->refresh();

        $this->assertSame('2026-10-20 12:00:00', $proposal->getRawOriginal('submitted_at'));
        $this->assertSame('2026-10-20T17:30:00+05:30', $timestamp->format('c'));
    }

    public function test_mass_assignment_cannot_set_or_change_identity_status_or_submission_time(): void
    {
        $proposal = Proposal::factory()->create();
        $other = Proposal::factory()->create();
        $protected = [
            'project_id' => $other->project_id, 'freelancer_id' => $other->freelancer_id,
            'status' => 'accepted', 'submitted_at' => '2000-01-01 00:00:00',
        ];
        foreach (array_keys($protected) as $field) {
            $this->assertFalse($proposal->isFillable($field));
        }
        $new = new Proposal($protected + ['cover_letter' => 'Allowed letter']);
        $this->assertNull($new->project_id);
        $this->assertNull($new->freelancer_id);
        $this->assertSame(ProposalStatus::Submitted, $new->status);
        $this->assertNull($new->submitted_at);
        $this->assertSame('Allowed letter', $new->cover_letter);

        $before = $proposal->getAttributes();
        $proposal->update($protected + ['cover_letter' => 'Updated letter']);
        $this->assertSame('Updated letter', $proposal->fresh()->cover_letter);
        foreach (array_keys($protected) as $field) {
            $this->assertSame($before[$field], $proposal->fresh()->getRawOriginal($field));
        }
    }

    #[DataProvider('statuses')]
    public function test_duplicate_pairs_are_rejected_in_every_status(ProposalStatus $status): void
    {
        $proposal = Proposal::factory()->create(['status' => $status]);
        $this->expectException(QueryException::class);

        Proposal::factory()->create(['project_id' => $proposal->project_id, 'freelancer_id' => $proposal->freelancer_id]);
    }

    public function test_different_projects_or_freelancers_have_distinct_pairs(): void
    {
        $first = Proposal::factory()->create();
        $second = Proposal::factory()->create(['project_id' => $first->project_id]);
        $third = Proposal::factory()->create(['freelancer_id' => $first->freelancer_id]);

        $this->assertDatabaseCount('proposals', 3);
        $this->assertSame([$first->id, $second->id], $first->project->proposals()->orderBy('id')->pluck('id')->all());
        $this->assertSame([$first->id, $third->id], $first->freelancer->submittedProposals()->orderBy('id')->pluck('id')->all());
    }

    #[DataProvider('foreignKeys')]
    public function test_project_and_freelancer_must_exist(string $foreignKey): void
    {
        $this->expectException(QueryException::class);
        Proposal::factory()->create([$foreignKey => 999999]);
    }

    public function test_submitted_at_is_required_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        Proposal::factory()->create(['submitted_at' => null]);
    }

    #[DataProvider('referencedParents')]
    public function test_referenced_parents_cannot_be_deleted_in_any_proposal_status(string $parent, ProposalStatus $status): void
    {
        $proposal = Proposal::factory()->create(['status' => $status]);
        $record = $proposal->{$parent};

        try {
            $record->delete();
            $this->fail('Proposal history must prevent deletion of its parent.');
        } catch (QueryException) {
            $this->assertModelExists($record);
            $this->assertModelExists($proposal);
        }
    }

    public function test_deleting_a_proposal_does_not_delete_related_accounts_or_project(): void
    {
        $proposal = Proposal::factory()->create();
        $project = $proposal->project;
        $freelancer = $proposal->freelancer;
        $proposal->delete();

        $this->assertModelMissing($proposal);
        $this->assertModelExists($project);
        $this->assertModelExists($project->client);
        $this->assertModelExists($freelancer);
    }

    public static function budgets(): array
    {
        return ['zero' => ['0', '0.00'], 'whole' => ['35000', '35000.00'], 'fraction' => ['12345.67', '12345.67'], 'smallest fraction' => ['0.01', '0.01'], 'maximum' => ['9999999999.99', '9999999999.99']];
    }

    public static function statuses(): array
    {
        return array_map(fn (ProposalStatus $status) => [$status], ProposalStatus::cases());
    }

    public static function submissionTimes(): array
    {
        return ['UTC string' => ['2026-10-20 12:00:00'], 'UTC offset' => ['2026-10-20T12:00:00+00:00'], 'Sri Lanka offset' => ['2026-10-20T17:30:00+05:30'], 'negative offset' => ['2026-10-20T08:00:00-04:00']];
    }

    public static function foreignKeys(): array
    {
        return ['project' => ['project_id'], 'freelancer' => ['freelancer_id']];
    }

    public static function referencedParents(): array
    {
        $cases = [];
        foreach (['project', 'freelancer'] as $parent) {
            foreach (ProposalStatus::cases() as $status) {
                $cases[$parent.' '.$status->value] = [$parent, $status];
            }
        }

        return $cases;
    }
}
