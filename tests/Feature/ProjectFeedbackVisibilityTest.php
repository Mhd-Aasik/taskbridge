<?php

namespace Tests\Feature;

use App\Actions\Notifications\ManageNotifications;
use App\Enums\NotificationEvent;
use App\Models\Project;
use App\Models\ProjectRating;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ProjectReputation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProjectFeedback;
use Tests\TestCase;

class ProjectFeedbackVisibilityTest extends TestCase
{
    use BuildsProjectFeedback, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 18:30:00', 'UTC'));
    }

    #[DataProvider('parties')]
    public function test_own_feedback_is_readable_but_the_counterparty_cannot_receive_hidden_content_in_any_page_or_payload(bool $client): void
    {
        $assignment = $this->completedAssignment();
        $author = $client ? $assignment->project->client : $assignment->freelancer;
        $other = $client ? $assignment->freelancer : $assignment->project->client;
        $secret = 'HIDDEN-RATING-COMMENT-3f4ce86b';
        $rating = $this->submitFeedback($assignment, $author, ['rating' => 2, 'feedback' => $secret]);
        $this->actingAs($author)->get(route('projects.feedback', $assignment->project))->assertOk()->assertSeeText($secret)->assertSeeText('Your rating: 2 / 5');
        $this->assertTrue(Gate::forUser($author)->allows('view', [$rating, $assignment, $assignment->project]));
        $this->assertFalse(Gate::forUser($other)->allows('view', [$rating, $assignment, $assignment->project]));

        $this->actingAs($other)->get(route('projects.feedback', $assignment->project))->assertOk()->assertDontSee($secret)->assertSeeText('Awaiting shared feedback.');
        $page = Volt::test('projects.feedback', ['project' => $assignment->project])->assertViewHas('own', null)->assertViewHas('received', null)->assertSet('rating', '')->assertSet('feedback', '')->call('refreshFeedback')->assertDontSee($secret);
        $this->assertStringNotContainsString($secret, json_encode([$page->snapshot, $page->effects]));
        $this->assertFalse($page->viewData('assignment')->relationLoaded('ratings'));
        $this->assertFalse($page->viewData('project')->relationLoaded('ratings'));
        foreach (['notifications.index', 'notifications.bell'] as $component) {
            $alerts = Volt::test($component)->assertDontSee($secret);
            $this->assertStringNotContainsString($secret, json_encode([$alerts->snapshot, $alerts->effects]));
        }
        $notification = $other->notifications()->where('type', NotificationEvent::ProjectFeedbackSubmitted->value)->sole();
        $this->assertSame(['record_id' => $rating->id], $notification->data);
        $this->assertStringNotContainsString($secret, $notification->toJson());
        $this->assertSame(['url' => route('projects.feedback', $assignment->project), 'available' => true], app(ManageNotifications::class)->open($notification->id));
        $this->get(route('projects.feedback', $assignment->project))->assertDontSee($secret);
        $this->get(route($other->role.'.dashboard'))->assertOk()->assertSeeText('Not rated yet')->assertDontSee($secret);
        Volt::test('settings.profile')->assertViewHas('reputation', ['count' => 0, 'average' => null, 'role' => $other->role])->assertDontSee($secret);
        if ($other->isFreelancer()) {
            $this->get(route('freelancer.profile.edit'))->assertOk()->assertDontSee($secret);
            $open = Project::factory()->create(['client_id' => $author->id, 'status' => 'open', 'published_at' => now()->subDay()]);
            $this->get(route('freelancer.projects.show', $open))->assertOk()->assertDontSee($secret);
        } else {
            $project = Project::factory()->create(['client_id' => $other->id]);
            $proposal = Proposal::factory()->create(['project_id' => $project->id, 'freelancer_id' => $author->id]);
            $this->get(route('client.projects.proposals.index', $project))->assertOk()->assertDontSee($secret);
            $this->get(route('client.projects.proposals.show', [$project, $proposal]))->assertOk()->assertViewHas('freelancerReputation', ['count' => 0, 'average' => null, 'role' => User::ROLE_FREELANCER])->assertDontSee($secret);
        }
        $this->assertSame(0, ProjectReputation::forUsers([$other->id], $other->role)[$other->id]['count']);
    }

    public function test_both_submissions_release_immediately_without_rewriting_either_record(): void
    {
        $assignment = $this->completedAssignment();
        $first = $this->submitFeedback($assignment, $assignment->project->client, ['rating' => 5, 'feedback' => 'First original feedback']);
        $before = $first->getAttributes();
        $second = $this->submitFeedback($assignment, $assignment->freelancer, ['rating' => 3, 'feedback' => 'Second original feedback']);
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertSame(2, ProjectRating::released()->count());
        foreach ([[$assignment->project->client, $second], [$assignment->freelancer, $first]] as [$viewer, $received]) {
            $this->actingAs($viewer)->get(route('projects.feedback', $assignment->project))->assertOk()->assertSeeText($received->feedback)->assertSeeText('Released feedback')->assertSeeText($received->rating.' / 5')->assertDontSee('Submit permanent feedback');
        }
    }

    public function test_simulated_two_open_forms_keep_feedback_blind_until_both_submissions_commit(): void
    {
        $assignment = $this->completedAssignment();
        $this->actingAs($assignment->project->client);
        $clientPage = Volt::test('projects.feedback', ['project' => $assignment->project])->set('rating', 3)->set('feedback', 'Client form comments')->set('confirmed', true);
        $this->actingAs($assignment->freelancer);
        $freelancerPage = Volt::test('projects.feedback', ['project' => $assignment->project])->set('rating', 5)->set('feedback', 'Freelancer form comments')->set('confirmed', true);
        // Sequential interleaving of stale forms; this is not parallel MySQL execution.
        $freelancerPage->call('submit')->assertHasNoErrors();
        $original = ProjectRating::sole()->getAttributes();
        $this->actingAs($assignment->project->client);
        $clientPage->call('refreshFeedback')->assertViewHas('received', null)->assertDontSee('Freelancer form comments');
        $clientPage->call('submit')->assertHasNoErrors()->assertSeeText('Freelancer form comments');
        $this->actingAs($assignment->freelancer);
        $this->travel(1)->minutes();
        $freelancerPage->call('refreshFeedback')->assertSeeText('Client form comments')->call('submit')->assertHasNoErrors();
        $this->assertSame($original, ProjectRating::where('reviewer_id', $assignment->freelancer_id)->sole()->getAttributes());
        $this->assertDatabaseCount('project_ratings', 2);
        $this->assertDatabaseCount('notifications', 2);
    }

    #[DataProvider('releaseBoundaries')]
    public function test_time_expiry_releases_unilateral_feedback_without_get_or_poll_mutations(int $seconds, bool $released): void
    {
        $start = CarbonImmutable::parse('2026-10-20 18:29:59', 'UTC');
        $assignment = $this->completedAssignment($start);
        $rating = $this->feedbackFrom($assignment, true, ['feedback' => 'Release this only at the exact deadline', 'submitted_at' => $start->addDay()]);
        $before = $rating->getAttributes();
        $projectBefore = $assignment->project->getAttributes();
        $this->travelTo($start->addSeconds($seconds));
        $this->actingAs($assignment->freelancer);
        $page = Volt::test('projects.feedback', ['project' => $assignment->project])->call('refreshFeedback');
        $this->assertSame($released, $page->viewData('received') !== null);
        $this->assertSame($released ? 1 : 0, ProjectReputation::forUsers([$assignment->freelancer_id], User::ROLE_FREELANCER)[$assignment->freelancer_id]['count']);
        $this->assertSame($before, $rating->fresh()->getAttributes());
        $this->assertSame($projectBefore, $assignment->project->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_other_assignments_or_invalid_participant_records_do_not_release_hidden_feedback(): void
    {
        $assignment = $this->completedAssignment();
        $rating = $this->feedbackFrom($assignment);
        $other = $this->completedAssignment();
        $this->feedbackFrom($other, false);
        $this->feedbackFrom($assignment, false, ['reviewer_id' => User::factory()->create()->id]);
        $this->assertFalse(ProjectRating::whereKey($rating->id)->released()->exists());
        $this->assertSame(0, ProjectRating::released()->count());
    }

    public function test_missing_completion_metadata_does_not_release_historical_feedback_but_author_keeps_own_access(): void
    {
        $assignment = $this->completedAssignment(now('UTC')->toImmutable()->subDays(30));
        $rating = $this->feedbackFrom($assignment, true, ['feedback' => 'Original author history']);
        DB::table('projects')->where('id', $assignment->project_id)->update(['completed_at' => null]);
        $this->actingAs($rating->reviewer)->get(route('projects.feedback', $assignment->project))->assertOk()->assertSeeText('Original author history')->assertSeeText('Missing historical completion metadata is not estimated.');
        $this->actingAs($rating->reviewee)->get(route('projects.feedback', $assignment->project))->assertOk()->assertDontSee('Original author history');
        $this->assertSame(0, ProjectRating::released()->count());
    }

    public static function parties(): array
    {
        return [[true], [false]];
    }

    public static function releaseBoundaries(): array
    {
        return [[1209599, false], [1209600, true], [1209601, true]];
    }
}
