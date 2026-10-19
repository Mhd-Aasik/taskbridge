<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientProjectEditorTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
        $this->category = Category::factory()->create();
    }

    public function test_a_client_can_create_a_private_draft_and_reload_its_saved_values(): void
    {
        $user = $this->client();
        $details = $this->details(['title' => '  Accessible website  ', 'budget' => '00025000.5']);

        Volt::test('client.projects.editor')->set($details)->call('save')->assertHasNoErrors()
            ->assertRedirect(route('client.projects.edit', Project::sole()));

        $project = Project::sole();
        $this->assertSame($user->id, $project->client_id);
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertNull($project->published_at);
        $this->assertSame('Accessible website', $project->title);
        $this->assertSame('25000.50', $project->budget);
        $this->assertSame(User::ROLE_CLIENT, $user->fresh()->role);
        $this->get(route('client.projects.edit', $project))->assertOk()->assertSeeText('Project draft saved.');

        Volt::test('client.projects.editor', ['project' => $project])
            ->assertSet('form.title', 'Accessible website')
            ->assertSet('form.description', $project->description)
            ->assertSet('form.budget', '25000.50')
            ->assertSet('form.category_id', (string) $this->category->id)
            ->assertSet('form.deadline', '2026-10-20')
            ->assertSeeText('Save changes');
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_an_owned_draft_can_be_updated_without_changing_protected_fields(): void
    {
        $user = $this->client();
        $project = Project::factory()->create(['client_id' => $user->id]);

        Volt::test('client.projects.editor', ['project' => $project])
            ->set($this->details(['title' => 'Revised brief', 'budget' => '12500.01']))
            ->call('save')->assertHasNoErrors()->assertRedirect(route('client.projects.edit', $project));

        $project->refresh();
        $this->assertSame('Revised brief', $project->title);
        $this->assertSame('12500.01', $project->budget);
        $this->assertSame($user->id, $project->client_id);
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertNull($project->published_at);
        $this->get(route('client.projects.edit', $project))->assertSeeText('Project changes saved.');
    }

    public function test_action_arguments_cannot_change_ownership_status_or_publication_on_create_or_update(): void
    {
        $user = $this->client();
        $target = Project::factory()->create();
        $payload = ['client_id' => $target->client_id, 'projectId' => $target->id, 'status' => 'open', 'published_at' => now()->toDateTimeString()];

        Volt::test('client.projects.editor')->set($this->details())->call('save', $payload)->assertHasNoErrors();
        $own = $user->projects()->sole();
        Volt::test('client.projects.editor', ['project' => $own])->set('form.title', 'Still my draft')->call('save', $payload)->assertHasNoErrors();

        $own->refresh();
        $this->assertSame($user->id, $own->client_id);
        $this->assertSame(ProjectStatus::Draft, $own->status);
        $this->assertNull($own->published_at);
        $this->assertSame('Still my draft', $own->title);
        $this->assertNotSame('Still my draft', $target->fresh()->title);
        $this->assertDatabaseCount('projects', 2);
    }

    #[DataProvider('protectedFields')]
    public function test_protected_fields_are_not_editable_component_properties(string $field): void
    {
        $this->client();
        $this->expectException(PublicPropertyNotFoundException::class);

        try {
            Volt::test('client.projects.editor')->set($field, '1');
        } finally {
            $this->assertDatabaseCount('projects', 0);
        }
    }

    public function test_the_project_identity_cannot_be_changed_in_a_livewire_request(): void
    {
        $user = $this->client();
        $own = Project::factory()->create(['client_id' => $user->id]);
        $other = Project::factory()->create();
        $before = $other->fresh()->getAttributes();
        $this->expectException(CannotUpdateLockedPropertyException::class);

        try {
            Volt::test('client.projects.editor', ['project' => $own])->set('projectId', $other->id);
        } finally {
            $this->assertEquals($before, $other->fresh()->getAttributes());
        }
    }

    #[DataProvider('protectedFields')]
    public function test_protected_fields_cannot_be_injected_into_the_nested_form(string $field): void
    {
        $user = $this->client();
        Volt::test('client.projects.editor')->set($this->details())->set('form.'.$field, '1')->assertForbidden();

        $this->assertDatabaseCount('projects', 0);
        $this->assertSame(User::ROLE_CLIENT, $user->fresh()->role);
    }

    public function test_another_clients_draft_cannot_be_opened(): void
    {
        $this->client();
        $project = Project::factory()->create();

        $this->get(route('client.projects.edit', $project))->assertForbidden();
        Volt::test('client.projects.editor', ['project' => $project])->assertForbidden();
    }

    #[DataProvider('nonDrafts')]
    public function test_non_drafts_cannot_be_opened_for_editing(ProjectStatus $status): void
    {
        $user = $this->client();
        $project = Project::factory()->create(['client_id' => $user->id, 'status' => $status]);

        $this->get(route('client.projects.edit', $project))->assertForbidden();
        Volt::test('client.projects.editor', ['project' => $project])->assertForbidden();
    }

    public function test_saving_rechecks_the_persisted_draft_status(): void
    {
        $user = $this->client();
        $project = Project::factory()->create(['client_id' => $user->id]);
        $component = Volt::test('client.projects.editor', ['project' => $project])->set('form.title', 'Cannot overwrite');
        $project->status = ProjectStatus::Open;
        $project->save();
        $before = $project->fresh()->getAttributes();

        $component->call('save')->assertForbidden();

        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    public function test_saving_rechecks_the_persisted_owner(): void
    {
        $user = $this->client();
        $project = Project::factory()->create(['client_id' => $user->id]);
        $component = Volt::test('client.projects.editor', ['project' => $project])->set('form.title', 'Cannot overwrite');
        $project->client_id = User::factory()->create(['role' => User::ROLE_CLIENT])->id;
        $project->save();
        $before = $project->fresh()->getAttributes();

        $component->call('save')->assertForbidden();

        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    public function test_saving_rechecks_the_accounts_role(): void
    {
        $user = $this->client();
        $component = Volt::test('client.projects.editor')->set($this->details());
        $user->role = User::ROLE_FREELANCER;
        $user->save();

        $component->call('save')->assertForbidden();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_all_fields_are_required(): void
    {
        $this->client();
        Volt::test('client.projects.editor')->call('save')->assertHasErrors([
            'form.title' => 'required', 'form.description' => 'required', 'form.category_id' => 'required', 'form.budget' => 'required', 'form.deadline' => 'required',
        ]);
        $this->assertDatabaseCount('projects', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_values_are_rejected_and_retained_without_creating_a_project(string $field, string $value): void
    {
        $this->client();

        Volt::test('client.projects.editor')->set($this->details([$field => $value]))
            ->call('save')->assertHasErrors(['form.'.$field])->assertSet('form.'.$field, trim($value))->assertNoRedirect();

        $this->assertDatabaseCount('projects', 0);
    }

    #[DataProvider('validBudgets')]
    public function test_money_is_saved_exactly_without_float_rounding(string $input, string $expected): void
    {
        $this->client();
        Volt::test('client.projects.editor')->set($this->details(['budget' => $input]))->call('save')->assertHasNoErrors();

        $this->assertSame($expected, Project::sole()->budget);
    }

    public function test_maximum_title_and_description_lengths_are_accepted(): void
    {
        $this->client();
        Volt::test('client.projects.editor')->set($this->details(['title' => str_repeat('T', 150), 'description' => str_repeat('D', 10000)]))->call('save')->assertHasNoErrors();

        $this->assertSame(150, strlen(Project::sole()->title));
        $this->assertSame(10000, strlen(Project::sole()->description));
    }

    public function test_failed_validation_preserves_the_existing_project_and_entered_values(): void
    {
        $user = $this->client();
        $project = Project::factory()->create(['client_id' => $user->id]);
        $before = $project->fresh()->getAttributes();

        Volt::test('client.projects.editor', ['project' => $project])
            ->set($this->details(['title' => 'Keep this entered title', 'budget' => '0.99']))
            ->call('save')->assertHasErrors(['form.budget'])
            ->assertSet('form.title', 'Keep this entered title')->assertSet('form.budget', '0.99');

        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    public function test_inactive_saved_categories_are_shown_but_require_an_active_replacement(): void
    {
        $user = $this->client();
        $inactive = Category::factory()->create(['is_active' => false, 'name' => 'Archived service']);
        $project = Project::factory()->create(['client_id' => $user->id, 'category_id' => $inactive->id]);

        $component = Volt::test('client.projects.editor', ['project' => $project])
            ->assertSeeText('Saved category: Archived service (inactive).')
            ->assertSeeText('Select an active category before saving this draft.')
            ->assertSet('form.category_id', (string) $inactive->id)
            ->set('form.deadline', '2026-10-20')
            ->call('save')->assertHasErrors(['form.category_id']);
        $this->assertSame($inactive->id, $project->fresh()->category_id);

        $component->set('form.category_id', (string) $this->category->id)->call('save')->assertHasNoErrors();
        $this->assertSame($this->category->id, $project->fresh()->category_id);
    }

    public function test_new_drafts_cannot_select_an_inactive_category(): void
    {
        $this->client();
        $inactive = Category::factory()->create(['is_active' => false, 'name' => 'Inactive choice']);
        Volt::test('client.projects.editor')->assertDontSeeText('Inactive choice')
            ->set($this->details(['category_id' => (string) $inactive->id]))->call('save')->assertHasErrors(['form.category_id']);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_category_activity_is_rechecked_after_validation_before_writing(): void
    {
        $this->client();
        $component = Volt::test('client.projects.editor')->set($this->details());
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed): void {
            if (! $changed && str_contains($query->sql, 'categories') && str_contains($query->sql, 'aggregate')) {
                $changed = true;
                $this->category->update(['is_active' => false]);
            }
        });

        $component->call('save')->assertHasErrors(['form.category_id']);

        $this->assertTrue($changed);
        $this->assertDatabaseCount('projects', 0);
    }

    #[DataProvider('colomboBoundaries')]
    public function test_deadline_validation_uses_sri_lankas_calendar_date(string $utc, string $deadline, bool $valid, string $minimum): void
    {
        $this->client();
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->get(route('client.projects.create'))->assertOk()->assertSee('min="'.$minimum.'"', false);
        $component = Volt::test('client.projects.editor')->set($this->details(['deadline' => $deadline]))->call('save');

        $valid ? $component->assertHasNoErrors() : $component->assertHasErrors(['form.deadline']);
        $this->assertDatabaseCount('projects', $valid ? 1 : 0);
    }

    public function test_a_page_opened_before_colombo_midnight_revalidates_the_date_when_saved(): void
    {
        $this->client();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 18:29:59', 'UTC'));
        $component = Volt::test('client.projects.editor')->set($this->details(['deadline' => '2026-10-20']));
        $this->travelTo(CarbonImmutable::parse('2026-10-19 18:30:00', 'UTC'));

        $component->call('save')->assertHasErrors(['form.deadline']);

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_descriptions_are_saved_as_text_and_rendered_escaped_in_editor_and_list(): void
    {
        $this->client();
        $text = '<script>alert("unsafe")</script> & a plain-text brief.';
        Volt::test('client.projects.editor')->set($this->details(['description' => $text]))->call('save')->assertHasNoErrors();
        $project = Project::sole();

        $this->assertSame($text, $project->description);
        foreach (['client.projects.edit', 'client.projects.index', 'client.dashboard'] as $route) {
            $this->get(route($route, $route === 'client.projects.edit' ? $project : []))
                ->assertOk()->assertSee(e($text), false)->assertDontSee($text, false);
        }
    }

    private function client(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($user);

        return $user;
    }

    private function details(array $overrides = []): array
    {
        $details = array_replace([
            'title' => 'Accessible company website',
            'description' => "Build a clear, accessible website.\nInclude responsive page layouts.",
            'category_id' => (string) $this->category->id,
            'budget' => '25000.50',
            'deadline' => '2026-10-20',
        ], $overrides);

        return array_combine(array_map(fn ($key) => 'form.'.$key, array_keys($details)), array_values($details));
    }

    public static function invalidFields(): array
    {
        return [
            'blank title' => ['title', '   '], 'long title' => ['title', str_repeat('T', 151)],
            'blank description' => ['description', '   '], 'long description' => ['description', str_repeat('D', 10001)],
            'unknown category' => ['category_id', '999999'], 'fractional category' => ['category_id', '1.5'],
            'zero budget' => ['budget', '0'], 'below minimum' => ['budget', '0.99'], 'negative budget' => ['budget', '-1.00'],
            'over maximum' => ['budget', '10000000000.00'], 'third decimal' => ['budget', '1.001'],
            'scientific notation' => ['budget', '1e3'], 'comma amount' => ['budget', '1,000.00'], 'not a number' => ['budget', 'NaN'],
            'signed amount' => ['budget', '+1.00'], 'unfinished fraction' => ['budget', '1.'],
            'today' => ['deadline', '2026-10-19'], 'past date' => ['deadline', '2026-10-18'],
            'invalid calendar date' => ['deadline', '2027-02-29'], 'date with time' => ['deadline', '2026-10-20T12:00:00Z'],
        ];
    }

    public static function validBudgets(): array
    {
        return ['minimum' => ['1', '1.00'], 'one decimal' => ['12.5', '12.50'], 'leading zeros' => ['0001.01', '1.01'], 'large precise amount' => ['9876543210.98', '9876543210.98'], 'maximum' => ['9999999999.99', '9999999999.99']];
    }

    public static function nonDrafts(): array
    {
        return array_map(fn (ProjectStatus $status) => [$status], array_filter(ProjectStatus::cases(), fn ($status) => $status !== ProjectStatus::Draft));
    }

    public static function protectedFields(): array
    {
        return ['owner' => ['client_id'], 'status' => ['status'], 'publication' => ['published_at']];
    }

    public static function colomboBoundaries(): array
    {
        return [
            'before midnight accepts tomorrow' => ['2026-10-19 18:29:59', '2026-10-20', true, '2026-10-20'],
            'at midnight rejects new today' => ['2026-10-19 18:30:00', '2026-10-20', false, '2026-10-21'],
            'at midnight accepts new tomorrow' => ['2026-10-19 18:30:00', '2026-10-21', true, '2026-10-21'],
        ];
    }
}
