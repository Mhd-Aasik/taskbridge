<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProjectFeedback;
use Tests\TestCase;

class ProjectFeedbackPagesTest extends TestCase
{
    use BuildsProjectFeedback, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 18:30:00', 'UTC'));
    }

    public function test_guest_redirects_and_get_requests_never_create_or_submit_feedback(): void
    {
        $assignment = $this->completedAssignment();
        $url = route('projects.feedback', $assignment->project);
        $this->get($url)->assertRedirect(route('login'));
        foreach ([$assignment->project->client, $assignment->freelancer] as $user) {
            $this->actingAs($user)->get($url.'?rating=5&confirmed=1')->assertOk()->assertSeeText('Your submitted feedback')->assertSeeText('Submit permanent feedback')->assertSeeText('Submit before the closing time.');
        }
        $this->assertDatabaseCount('project_ratings', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    #[DataProvider('roles')]
    public function test_unrelated_accounts_and_admins_cannot_view_or_submit(string $role): void
    {
        $assignment = $this->completedAssignment();
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route('projects.feedback', $assignment->project))->assertForbidden();
        Volt::test('projects.feedback', ['project' => $assignment->project])->assertForbidden();
        $this->assertDatabaseCount('project_ratings', 0);
    }

    public function test_unassigned_projects_have_no_feedback_workspace(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client)->get(route('projects.feedback', $project))->assertNotFound();
    }

    public function test_empty_closed_and_missing_metadata_states_and_sri_lankan_times_are_clear(): void
    {
        $assignment = $this->completedAssignment(CarbonImmutable::parse('2026-10-31 18:30:00', 'UTC'));
        $this->actingAs($assignment->freelancer)->get(route('projects.feedback', $assignment->project))->assertOk()->assertSeeText('1 November 2026, 12:00:00 am')->assertSeeText('15 November 2026, 12:00:00 am')->assertSeeText('Awaiting shared feedback.');
        $this->travel(20)->days();
        $this->get(route('projects.feedback', $assignment->project))->assertOk()->assertSeeText('The feedback window is closed.')->assertSeeText('No feedback was received')->assertDontSee('Submit permanent feedback');
    }

    public function test_validation_retains_input_and_success_confirmation_is_accessible(): void
    {
        $assignment = $this->completedAssignment();
        $this->actingAs($assignment->freelancer);
        $page = Volt::test('projects.feedback', ['project' => $assignment->project])->set('rating', 6)->set('feedback', 'Keep these written comments')->set('confirmed', true)->call('submit')->assertHasErrors(['rating'])->assertSet('rating', 6)->assertSet('feedback', 'Keep these written comments');
        $page->set('rating', 4)->call('submit')->assertHasNoErrors()->assertSeeText('Your feedback was submitted.')->assertSee('aria-live="polite"', false)->assertSeeText('Your rating: 4 / 5')->assertDontSee('Submit permanent feedback');
        $this->assertDatabaseCount('project_ratings', 1);
    }

    public function test_complete_feedback_is_escaped_and_newlines_preserved_after_release(): void
    {
        $assignment = $this->completedAssignment();
        $text = "<script>alert('feedback')</script>\nA second line.";
        $rating = $this->feedbackFrom($assignment, true, ['feedback' => $text]);
        $this->feedbackFrom($assignment, false);
        foreach ([$rating->reviewer, $rating->reviewee] as $user) {
            $this->actingAs($user)->get(route('projects.feedback', $assignment->project))->assertOk()->assertSee(e($text), false)->assertDontSee($text, false)->assertSee('whitespace-pre-wrap', false);
        }
    }

    public function test_completed_project_assignment_and_completion_pages_link_to_feedback(): void
    {
        $assignment = $this->completedAssignment();
        $url = route('projects.feedback', $assignment->project);
        $this->actingAs($assignment->project->client)->get(route('client.projects.show', $assignment->project))->assertOk()->assertSee('href="'.$url.'"', false);
        $this->get(route('projects.completion', $assignment->project))->assertOk()->assertSee('href="'.$url.'"', false);
        $this->actingAs($assignment->freelancer)->get(route('freelancer.assignments.show', $assignment))->assertOk()->assertSee('href="'.$url.'"', false);
        $this->get(route('projects.completion', $assignment->project))->assertOk()->assertSee('href="'.$url.'"', false);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
