<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ProjectReputation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\Concerns\BuildsProjectFeedback;
use Tests\TestCase;

class ProjectReputationTest extends TestCase
{
    use BuildsProjectFeedback, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 18:30:00', 'UTC'));
    }

    public function test_reputation_uses_released_scores_only_with_one_query_for_multiple_users(): void
    {
        $first = $this->completedAssignment();
        $this->feedbackFrom($first, true, ['rating' => 5]);
        $this->feedbackFrom($first, false);
        $secondProposal = Proposal::factory()->create(['freelancer_id' => $first->freelancer_id]);
        $second = ProjectAssignment::factory()->create(['proposal_id' => $secondProposal->id]);
        $second->project->forceFill(['status' => 'completed', 'completed_at' => now()->subDay(), 'completed_by' => $second->project->client_id])->save();
        $this->feedbackFrom($second, true, ['rating' => 3]);
        $this->feedbackFrom($second, false);
        $hidden = $this->completedAssignment();
        $hiddenProposal = Proposal::factory()->create(['freelancer_id' => $first->freelancer_id]);
        $hiddenAssignment = ProjectAssignment::factory()->create(['proposal_id' => $hiddenProposal->id]);
        $this->feedbackFrom($hiddenAssignment, true, ['rating' => 1]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $summaries = ProjectReputation::forUsers([$first->freelancer_id, $hidden->freelancer_id], User::ROLE_FREELANCER);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertSame(['count' => 2, 'average' => '4.0', 'role' => User::ROLE_FREELANCER], $summaries[$first->freelancer_id]);
        $this->assertSame(['count' => 0, 'average' => null, 'role' => User::ROLE_FREELANCER], $summaries[$hidden->freelancer_id]);
        $this->assertSame([], ProjectReputation::forUsers([], User::ROLE_CLIENT));
    }

    public function test_historical_client_and_freelancer_reputations_are_separate_from_current_account_role(): void
    {
        $assignment = $this->completedAssignment(now('UTC')->toImmutable()->subDays(20));
        $user = $assignment->freelancer;
        $this->feedbackFrom($assignment, true, ['rating' => 5, 'submitted_at' => now()->subDays(19)]);
        $user->forceFill(['role' => User::ROLE_CLIENT])->save();
        $project = Project::factory()->create(['client_id' => $user->id]);
        $proposal = Proposal::factory()->create(['project_id' => $project->id]);
        $asClient = ProjectAssignment::factory()->create(['proposal_id' => $proposal->id]);
        $project->refresh()->forceFill(['status' => 'completed', 'completed_at' => now()->subDays(20), 'completed_by' => $user->id])->save();
        $this->feedbackFrom($asClient, false, ['rating' => 1, 'submitted_at' => now()->subDays(19)]);
        $user->forceFill(['role' => User::ROLE_FREELANCER])->save();
        $this->assertSame(['count' => 1, 'average' => '5.0', 'role' => User::ROLE_FREELANCER], ProjectReputation::forUsers([$user->id], User::ROLE_FREELANCER)[$user->id]);
        $this->assertSame(['count' => 1, 'average' => '1.0', 'role' => User::ROLE_CLIENT], ProjectReputation::forUsers([$user->id], User::ROLE_CLIENT)[$user->id]);
        $this->actingAs($user)->get(route('freelancer.dashboard'))->assertOk()->assertViewHas('reputation', ['count' => 1, 'average' => '5.0', 'role' => User::ROLE_FREELANCER]);
        Volt::test('settings.profile')->assertViewHas('reputation', ['count' => 1, 'average' => '5.0', 'role' => User::ROLE_FREELANCER]);
    }

    public function test_expiration_recalculates_without_cache_and_other_project_details_never_leak_into_summaries(): void
    {
        $start = now('UTC')->toImmutable()->subDays(13);
        $assignment = $this->completedAssignment($start);
        $assignment->project->forceFill(['title' => 'PRIVATE-PAST-PROJECT-SECRET'])->save();
        $this->feedbackFrom($assignment, true, ['rating' => 4, 'feedback' => 'PRIVATE-PAST-COMMENT', 'submitted_at' => $start->addDay()]);
        $id = $assignment->freelancer_id;
        $this->assertSame(0, ProjectReputation::forUsers([$id], User::ROLE_FREELANCER)[$id]['count']);
        $this->travelTo($start->addDays(14));
        $this->assertSame(1, ProjectReputation::forUsers([$id], User::ROLE_FREELANCER)[$id]['count']);
        $newProject = Project::factory()->create();
        $proposal = Proposal::factory()->create(['project_id' => $newProject->id, 'freelancer_id' => $id]);
        $this->actingAs($newProject->client);
        foreach ([route('client.projects.proposals.index', $newProject), route('client.projects.proposals.show', [$newProject, $proposal])] as $url) {
            $this->get($url)->assertOk()->assertSeeText('4.0 / 5')->assertSeeText('1 released rating')->assertDontSee('PRIVATE-PAST-PROJECT-SECRET')->assertDontSee('PRIVATE-PAST-COMMENT')->assertDontSee($assignment->freelancer->email);
        }
        $clientRating = $this->feedbackFrom($assignment, false, ['rating' => 3, 'submitted_at' => $start->addDays(2)]);
        $openProject = Project::factory()->create(['client_id' => $assignment->project->client_id, 'status' => 'open', 'published_at' => now()->subDay()]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]))->get(route('freelancer.projects.show', $openProject))->assertOk()->assertViewHas('clientReputation', ['count' => 1, 'average' => '3.0', 'role' => User::ROLE_CLIENT])->assertDontSee('PRIVATE-PAST-PROJECT-SECRET')->assertDontSee($clientRating->feedback)->assertDontSee($assignment->project->client->email);
    }
}
