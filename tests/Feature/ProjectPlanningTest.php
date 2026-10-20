<?php

namespace Tests\Feature;

use App\Actions\Milestones\SaveMilestone;
use App\Enums\ProjectStatus;
use App\Models\FreelancerProfile;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProjectPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));
    }

    public function test_guests_cannot_open_or_mutate_a_plan(): void
    {
        $assignment = $this->assignment();
        $this->get($this->url($assignment))->assertRedirect(route('login'));
        Volt::test('projects.plan', ['project' => $assignment->project])->assertForbidden();
        $this->expectException(HttpException::class);
        app(SaveMilestone::class)->save($assignment->project_id, null, $this->details());
    }

    #[DataProvider('roles')]
    public function test_unrelated_accounts_and_admins_cannot_view_planning(string $role): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'title' => 'Private plan title']);
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get($this->url($assignment))->assertForbidden()->assertDontSeeText($milestone->title);
        Volt::test('projects.plan', ['project' => $assignment->project])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, $this->details());
    }

    public function test_admin_access_is_not_implicitly_granted_even_with_historical_ownership(): void
    {
        $assignment = $this->assignment();
        $owner = $assignment->project->client;
        $owner->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->actingAs($owner)->get($this->url($assignment))->assertForbidden();
        $this->assertFalse(Gate::allows('viewPlan', [$assignment, $assignment->project]));
        $freelancer = $assignment->freelancer;
        $freelancer->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->actingAs($freelancer)->get($this->url($assignment))->assertForbidden();
    }

    public function test_an_assignment_is_required_and_get_requests_never_create_milestones(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client)->get(route('projects.plan', $project))->assertNotFound();
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client)->get($this->url($assignment).'?save=1&title=Forged&position=1')->assertOk()->assertSeeText('No milestones planned yet.');
        $this->assertDatabaseCount('milestones', 0);
        $this->post($this->url($assignment))->assertStatus(405);
        $this->get(route('projects.plan', 999999))->assertNotFound();
    }

    public function test_client_creates_trimmed_milestones_with_server_metadata_and_keeps_agreed_terms_unchanged(): void
    {
        $assignment = $this->assignment();
        $projectBefore = $assignment->project->fresh()->getAttributes();
        $assignmentBefore = $assignment->fresh()->getAttributes();
        $proposalBefore = $assignment->proposal->fresh()->getAttributes();
        $owner = $assignment->project->client;
        $this->actingAs($owner);
        $this->form($assignment, ['title' => '  Initial research  ', 'description' => "  Plain text.\nSecond line.  ", 'due_date' => '2026-10-21'])
            ->call('save')->assertHasNoErrors()->assertSeeText('Milestone added to the project plan.')->assertSet('form.title', '');
        $milestone = Milestone::sole();
        $this->assertSame('Initial research', $milestone->title);
        $this->assertSame("Plain text.\nSecond line.", $milestone->description);
        $this->assertSame($assignment->id, $milestone->project_assignment_id);
        $this->assertSame($owner->id, $milestone->created_by);
        $this->assertSame(1, $milestone->position);
        $this->assertSame('2026-10-21', $milestone->due_date->toDateString());
        $this->form($assignment)->call('save')->assertHasNoErrors();
        $this->assertSame([1, 2], $assignment->milestones()->pluck('position')->all());
        $this->assertSame($projectBefore, $assignment->project->fresh()->getAttributes());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getAttributes());
        $this->assertSame($proposalBefore, $assignment->proposal->fresh()->getAttributes());
    }

    public function test_client_can_load_update_and_cancel_editing_without_changing_metadata(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'title' => 'Saved title', 'description' => "Saved\nlines", 'due_date' => '2026-10-22', 'position' => 3])->fresh();
        $before = $milestone->getAttributes();
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.plan', ['project' => $assignment->project])->call('edit', $milestone->id)->assertHasNoErrors()
            ->assertDispatched('milestone-editing')->assertSet('editingMilestoneId', $milestone->id)->assertSet('form.title', 'Saved title')
            ->assertSet('form.description', "Saved\nlines")->assertSet('form.due_date', '2026-10-22');
        $this->travel(1)->hours();
        $form->set('form.title', '  Revised title  ')->set('form.description', '')->set('form.due_date', '2026-10-25')->call('save')
            ->assertHasNoErrors()->assertSeeText('Milestone changes saved.')->assertSet('editingMilestoneId', null);
        $milestone->refresh();
        $this->assertSame('Revised title', $milestone->title);
        $this->assertNull($milestone->description);
        foreach (['project_assignment_id', 'created_by', 'position', 'created_at'] as $field) {
            $this->assertSame($before[$field], $milestone->getRawOriginal($field));
        }
        $form->call('edit', $milestone->id)->set('form.title', 'Do not persist')->call('cancelEdit')->assertHasNoErrors()->assertSet('form.title', '')->assertSet('editingMilestoneId', null);
        $this->assertSame('Revised title', $milestone->fresh()->title);
    }

    public function test_freelancer_can_read_but_cannot_create_load_an_editor_or_save_changes(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->freelancer);
        $this->get($this->url($assignment))->assertOk()->assertSeeText($milestone->title)->assertSeeText('Only the owning Client')
            ->assertDontSee('wire:submit="save"', false)->assertDontSeeText('Edit milestone')->assertDontSeeText('Add a milestone');
        $this->form($assignment)->call('save')->assertForbidden();
        Volt::test('projects.plan', ['project' => $assignment->project])->call('edit', $milestone->id)->assertForbidden();
        $this->assertDatabaseCount('milestones', 1);
        $this->expectException(AuthorizationException::class);
        app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, $this->details());
    }

    public function test_nested_milestone_scope_rejects_other_assignments_even_with_the_same_client(): void
    {
        $first = $this->assignment();
        $second = $this->assignment();
        $second->project->forceFill(['client_id' => $first->project->client_id])->save();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $second->id]);
        $this->actingAs($first->project->client);
        Volt::test('projects.plan', ['project' => $first->project])->call('edit', $milestone->id)->assertNotFound();
        $this->assertFalse(Gate::allows('update', [$milestone, $first, $first->project]));
        $this->expectException(ModelNotFoundException::class);
        app(SaveMilestone::class)->save($first->project_id, $milestone->id, $this->details());
    }

    public function test_submitted_assignment_creator_position_and_other_system_fields_are_ignored(): void
    {
        $assignment = $this->assignment();
        $other = Milestone::factory()->create();
        $this->actingAs($assignment->project->client);
        $milestone = app(SaveMilestone::class)->save($assignment->project_id, null, $this->details() + [
            'id' => $other->id, 'project_assignment_id' => $other->project_assignment_id, 'assignment_id' => $other->project_assignment_id,
            'created_by' => $other->created_by, 'position' => 999, 'client_id' => $other->created_by, 'role' => 'admin',
            'status' => 'completed', 'agreed_budget' => '1.00', 'agreed_deadline' => '2099-01-01', 'created_at' => '2000-01-01',
        ]);
        $this->assertSame($assignment->id, $milestone->project_assignment_id);
        $this->assertSame($assignment->project->client_id, $milestone->created_by);
        $this->assertSame(1, $milestone->position);
        $this->assertNotSame($other->id, $milestone->id);
        $this->assertSame(User::ROLE_CLIENT, $assignment->project->client->fresh()->role);
    }

    #[DataProvider('forgedFormFields')]
    public function test_system_fields_cannot_be_injected_into_the_form(string $field): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->form($assignment)->set('form.'.$field, '1')->assertForbidden();
        $this->assertDatabaseCount('milestones', 0);
    }

    #[DataProvider('lockedFields')]
    public function test_component_identifiers_are_locked(string $field): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $form->set($field, 999999);
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_data_preserves_storage_and_entered_values(string $field, mixed $value): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id])->fresh();
        $before = $milestone->getAttributes();
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.plan', ['project' => $assignment->project])->call('edit', $milestone->id)
            ->set('form.title', 'Retained valid title')->set('form.description', 'Retained description')->set('form.due_date', '2026-10-24')
            ->set('form.'.$field, $value)->call('save')->assertHasErrors(['form.'.$field])->assertSet('editingMilestoneId', $milestone->id);
        if ($field !== 'title') {
            $form->assertSet('form.title', 'Retained valid title');
        }
        if ($field !== 'description') {
            $form->assertSet('form.description', 'Retained description');
        }
        $this->assertSame($before, $milestone->fresh()->getAttributes());
        $this->assertDatabaseCount('milestones', 1);
    }

    public function test_maximum_lengths_and_optional_description_are_supported(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->form($assignment, ['title' => str_repeat('T', 150), 'description' => str_repeat('D', 5000)])->call('save')->assertHasNoErrors();
        $this->assertSame(5000, strlen(Milestone::sole()->description));
        $this->form($assignment, ['description' => " \n "])->call('save')->assertHasNoErrors();
        $this->assertNull(Milestone::latest('id')->first()->description);
    }

    #[DataProvider('allowedDates')]
    public function test_assignment_interval_is_inclusive_and_permits_overdue_dates(string $date): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->form($assignment, ['due_date' => $date])->call('save')->assertHasNoErrors();
        $this->assertSame($date, Milestone::sole()->due_date->toDateString());
    }

    public function test_past_interval_remains_manageable_without_changing_agreed_deadline(): void
    {
        $assignment = $this->assignment();
        $before = $assignment->fresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));
        $this->actingAs($assignment->project->client);
        $this->form($assignment, ['due_date' => '2026-10-22'])->call('save')->assertHasNoErrors()->assertSeeText('Overdue');
        $this->assertSame($before, $assignment->fresh()->getAttributes());
    }

    #[DataProvider('readOnlyStatuses')]
    public function test_other_statuses_allow_both_parties_to_read_history_but_refuse_stale_saves(ProjectStatus $status): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id])->fresh();
        $before = $milestone->getAttributes();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment)->call('edit', $milestone->id)->set('form.title', 'Unsaved change');
        DB::table('projects')->where('id', $assignment->project_id)->update(['status' => $status->value]);
        $form->call('save')->assertHasErrors(['plan'])->assertSeeText('This plan is read-only')->assertSet('form.title', 'Unsaved change');
        foreach ([$assignment->project->client, $assignment->freelancer] as $user) {
            $this->actingAs($user)->get($this->url($assignment))->assertOk()->assertSeeText($milestone->title)->assertDontSee('wire:submit="save"', false);
        }
        $this->assertSame($before, $milestone->fresh()->getAttributes());
    }

    public function test_persisted_role_ownership_assignment_and_status_are_rechecked_during_mutations(): void
    {
        $assignment = $this->assignment();
        $owner = $assignment->project->client;
        $this->actingAs($owner);
        $form = $this->form($assignment);
        DB::table('users')->where('id', $owner->id)->update(['role' => User::ROLE_ADMIN]);
        $form->call('save')->assertForbidden();
        DB::table('users')->where('id', $owner->id)->update(['role' => User::ROLE_CLIENT]);
        $form = $this->form($assignment);
        DB::table('projects')->where('id', $assignment->project_id)->update(['client_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id]);
        $form->call('save')->assertForbidden();
        DB::table('projects')->where('id', $assignment->project_id)->update(['client_id' => $owner->id]);
        $form = $this->form($assignment);
        $assignment->delete();
        $form->call('save')->assertNotFound();
        $this->assertDatabaseCount('milestones', 0);
    }

    public function test_assigned_freelancer_identity_is_rechecked_on_subsequent_reads(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->freelancer);
        $form = Volt::test('projects.plan', ['project' => $assignment->project]);
        DB::table('project_assignments')->where('id', $assignment->id)->update(['freelancer_id' => User::factory()->create(['role' => User::ROLE_FREELANCER])->id]);
        $form->call('$refresh')->assertForbidden();
    }

    public function test_saved_date_bounds_are_rechecked_and_cannot_be_forged_by_a_stale_form(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $form = $this->form($assignment, ['due_date' => '2026-10-24']);
        // Trusted data changes are simulated here; the application exposes no term-editing form.
        DB::table('project_assignments')->where('id', $assignment->id)->update(['agreed_deadline' => '2026-10-23']);
        $form->call('save')->assertHasErrors(['form.due_date'])->assertSet('form.due_date', '2026-10-24');
        $this->assertDatabaseCount('milestones', 0);
    }

    public function test_the_plan_reads_agreed_terms_instead_of_the_original_project_deadline(): void
    {
        $assignment = $this->assignment();
        $assignment->project->update(['deadline' => '2026-10-22']);
        $this->actingAs($assignment->project->client);
        $this->form($assignment, ['due_date' => '2026-10-25'])->call('save')->assertHasNoErrors();
        $this->assertSame('2026-10-25', Milestone::sole()->due_date->toDateString());
    }

    public function test_query_counts_remain_constant_as_the_plan_grows(): void
    {
        $assignment = $this->assignment();
        Milestone::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->project->client);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select')) {
                $queries[] = $query->sql;
            }
        });
        $this->get($this->url($assignment))->assertOk();
        $single = count($queries);
        Milestone::factory()->count(9)->create(['project_assignment_id' => $assignment->id]);
        $queries = [];
        $this->get($this->url($assignment))->assertOk();
        $this->assertCount($single, $queries);
    }

    public function test_plans_render_in_position_order_and_show_escaped_content_and_overdue_state(): void
    {
        $assignment = $this->assignment();
        $third = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'position' => 3, 'title' => 'Third step', 'due_date' => '2026-10-25']);
        $first = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'position' => 1, 'title' => '<script>First step</script>', 'description' => "Plain line\n<script>Untrusted description</script>", 'due_date' => '2026-10-21']);
        $second = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'position' => 2, 'title' => 'Second step', 'due_date' => '2026-10-23']);
        $privateProfile = FreelancerProfile::factory()->create(['user_id' => $assignment->freelancer_id, 'bio' => 'Private freelancer biography']);
        $competitor = Proposal::factory()->create(['project_id' => $assignment->project_id, 'cover_letter' => 'Private competing offer']);
        $other = Milestone::factory()->create(['title' => 'Another assignment secret']);
        $before = $assignment->fresh()->getAttributes();
        foreach ([$assignment->project->client, $assignment->freelancer] as $user) {
            $this->actingAs($user);
            $response = $this->get($this->url($assignment))->assertOk()->assertSeeInOrder([e($first->title), $second->title, $third->title], false)
                ->assertSee(e($first->description), false)->assertDontSee($first->description, false)->assertSee('whitespace-pre-wrap', false)
                ->assertSeeText('Overdue')->assertDontSeeText($other->title)->assertDontSeeText($privateProfile->bio)->assertDontSeeText($competitor->cover_letter)
                ->assertDontSeeText('100%')->assertDontSeeText('Mark complete')->assertDontSeeText('Delete milestone');
            $otherParty = $user->id === $assignment->freelancer_id ? $assignment->project->client : $assignment->freelancer;
            $response->assertDontSee($otherParty->email)->assertDontSee(route('account.photo', $otherParty));
        }
        $this->assertSame($before, $assignment->fresh()->getAttributes());
        $this->actingAs($assignment->project->client);
        Volt::test('projects.plan', ['project' => $assignment->project])->assertViewHas('milestones', fn ($milestones) => $milestones->modelKeys() === [$first->id, $second->id, $third->id]);
    }

    public function test_links_and_empty_states_work_for_both_parties(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client)->get(route('client.projects.show', $assignment->project))->assertOk()->assertSee($this->url($assignment));
        $this->get($this->url($assignment))->assertOk()->assertSeeText('No milestones planned yet.')->assertSeeText('Add a milestone')
            ->assertSee(route('client.projects.show', $assignment->project))->assertSee('aria-label="Choose theme"', false)
            ->assertSee(route('settings.profile'))->assertSee(route('settings.password'))->assertSee(route('logout'));
        $this->actingAs($assignment->freelancer)->get(route('freelancer.assignments.show', $assignment))->assertOk()->assertSee($this->url($assignment));
        $this->get($this->url($assignment))->assertOk()->assertSeeText('No milestones planned yet.')->assertSeeText('Milestones created by the owning Client will appear here.')
            ->assertSee(route('freelancer.assignments.show', $assignment));
    }

    public function test_position_allocation_is_server_side_and_does_not_reorder_existing_milestones(): void
    {
        $assignment = $this->assignment();
        $existing = Milestone::factory()->create(['project_assignment_id' => $assignment->id, 'position' => 8])->fresh();
        $this->actingAs($assignment->project->client);
        $next = app(SaveMilestone::class)->save($assignment->project_id, null, $this->details() + ['position' => 1]);
        $this->assertSame(9, $next->position);
        app(SaveMilestone::class)->save($assignment->project_id, $existing->id, $this->details());
        $this->assertSame(8, $existing->fresh()->position);
        $this->assertSame([8, 9], $assignment->milestones()->pluck('position')->all());
    }

    public function test_a_failed_model_save_rolls_back_without_touching_existing_plan_or_terms(): void
    {
        $assignment = $this->assignment();
        $milestone = Milestone::factory()->create(['project_assignment_id' => $assignment->id])->fresh();
        $before = $milestone->getAttributes();
        $this->actingAs($assignment->project->client);
        Milestone::saving(fn () => false);
        try {
            app(SaveMilestone::class)->save($assignment->project_id, $milestone->id, $this->details());
            $this->fail('Expected save refusal.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The milestone could not be saved.', $exception->getMessage());
        } finally {
            Milestone::flushEventListeners();
        }
        $this->assertSame($before, $milestone->fresh()->getAttributes());
        $this->assertDatabaseCount('milestones', 1);
    }

    public function test_status_is_checked_after_a_simulated_change_before_the_project_lock(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($assignment, &$changed): void {
            if (! $changed && str_contains($query->sql, 'from "users"')) {
                $changed = true;
                DB::table('projects')->where('id', $assignment->project_id)->update(['status' => 'completed']);
            }
        });
        try {
            app(SaveMilestone::class)->save($assignment->project_id, null, $this->details());
            $this->fail('Expected read-only plan validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $this->assertTrue($changed);
        $this->assertDatabaseCount('milestones', 0);
    }

    private function assignment(): ProjectAssignment
    {
        return ProjectAssignment::factory()->create(['assigned_at' => '2026-10-20 18:30:00', 'agreed_deadline' => '2026-10-25']);
    }

    private function details(): array
    {
        return ['title' => 'Review the brief', 'description' => 'Confirm the intended deliverables.', 'due_date' => '2026-10-24'];
    }

    private function form(ProjectAssignment $assignment, array $changes = []): Testable
    {
        $form = Volt::test('projects.plan', ['project' => $assignment->project]);
        foreach (array_replace($this->details(), $changes) as $field => $value) {
            $form->set('form.'.$field, $value);
        }

        return $form;
    }

    private function url(ProjectAssignment $assignment): string
    {
        return route('projects.plan', $assignment->project_id);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function forgedFormFields(): array
    {
        return array_map(fn ($field) => [$field], ['project_assignment_id', 'created_by', 'position', 'id', 'status', 'agreed_deadline']);
    }

    public static function lockedFields(): array
    {
        return [['projectId'], ['editingMilestoneId']];
    }

    public static function invalidDetails(): array
    {
        return [
            ['title', ''], ['title', " \t\n"], ['title', "\u{00A0}\u{2002}"], ['title', str_repeat('T', 151)],
            ['description', str_repeat('D', 5001)], ['due_date', ''], ['due_date', 'not-a-date'],
            ['due_date', '2026-02-30'], ['due_date', '2026-10-20'], ['due_date', '2026-10-26'],
            ['due_date', '2026-10-24 12:00:00'],
        ];
    }

    public static function allowedDates(): array
    {
        return [['2026-10-21'], ['2026-10-22'], ['2026-10-23'], ['2026-10-25']];
    }

    public static function readOnlyStatuses(): array
    {
        return array_map(fn ($status) => [$status], array_filter(ProjectStatus::cases(), fn ($status) => $status !== ProjectStatus::InProgress));
    }
}
