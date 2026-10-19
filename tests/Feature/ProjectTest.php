<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_relationships_and_private_draft_defaults(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = Category::factory()->create();
        $project = $client->projects()->create([
            'title' => 'Build an accessible website',
            'description' => 'A plain-text project brief.',
            'category_id' => $category->id,
            'budget' => '45000.50',
            'deadline' => '2027-02-28',
        ])->refresh();

        $this->assertTrue($project->client->is($client));
        $this->assertTrue($project->category->is($category));
        $this->assertTrue($client->projects->sole()->is($project));
        $this->assertTrue($category->projects->sole()->is($project));
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertNull($project->published_at);
        $this->assertSame(ProjectStatus::Draft, (new Project)->status);
        $this->assertSame(User::ROLE_CLIENT, Project::factory()->create()->client->role);
    }

    public function test_database_defaults_also_apply_without_eloquent(): void
    {
        $attributes = Project::factory()->create()->getAttributes();
        unset($attributes['id'], $attributes['status'], $attributes['published_at']);
        $id = DB::table('projects')->insertGetId($attributes);

        $this->assertSame(ProjectStatus::Draft, Project::findOrFail($id)->status);
        $this->assertNull(Project::findOrFail($id)->published_at);
    }

    #[DataProvider('budgets')]
    public function test_budget_is_cast_to_a_two_decimal_lkr_string(string $input, string $expected): void
    {
        $project = Project::factory()->create(['budget' => $input])->refresh();

        $this->assertSame($expected, $project->budget);
        $this->assertSame($expected, $project->toArray()['budget']);
    }

    public function test_deadline_is_a_calendar_date_and_description_is_stored_as_plain_text(): void
    {
        $description = "First line.\nSecond line with <literal> characters & punctuation.";
        $project = Project::factory()->create(['deadline' => '2028-02-29', 'description' => $description])->refresh();

        $this->assertInstanceOf(CarbonImmutable::class, $project->deadline);
        $this->assertSame('2028-02-29', $project->deadline->toDateString());
        $this->assertSame('2028-02-29', $project->toArray()['deadline']);
        $this->assertSame($description, $project->description);
    }

    #[DataProvider('statuses')]
    public function test_status_is_a_backed_enum_and_publication_time_is_nullable(ProjectStatus $status): void
    {
        $project = Project::factory()->create();
        // Trusted assignments test storage casts, without introducing a transition API.
        $project->status = $status;
        $project->published_at = '2026-10-19 12:30:00';
        $project->save();

        $this->assertSame($status, $project->fresh()->status);
        $this->assertSame($status->value, $project->fresh()->toArray()['status']);
        $this->assertSame('2026-10-19 12:30:00', $project->fresh()->published_at->format('Y-m-d H:i:s'));
    }

    public function test_mass_assignment_cannot_set_or_change_ownership_status_or_publication_time(): void
    {
        $project = Project::factory()->create();
        $other = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $protected = ['client_id' => $other->id, 'status' => 'open', 'published_at' => '2026-10-19 12:30:00'];

        foreach (array_keys($protected) as $field) {
            $this->assertFalse($project->isFillable($field));
        }

        $new = new Project($protected + ['title' => 'Allowed title']);
        $this->assertNull($new->client_id);
        $this->assertSame(ProjectStatus::Draft, $new->status);
        $this->assertNull($new->published_at);

        $clientId = $project->client_id;
        $project->update($protected + ['title' => 'Updated title']);
        $this->assertSame('Updated title', $project->fresh()->title);
        $this->assertSame($clientId, $project->fresh()->client_id);
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        $this->assertNull($project->fresh()->published_at);
    }

    #[DataProvider('foreignKeys')]
    public function test_projects_require_existing_client_and_category_records(string $foreignKey): void
    {
        $this->expectException(QueryException::class);
        Project::factory()->create([$foreignKey => 999999]);
    }

    #[DataProvider('parents')]
    public function test_referenced_clients_and_categories_cannot_be_deleted(string $parent): void
    {
        $project = Project::factory()->create();
        $record = $project->{$parent};

        try {
            $record->delete();
            $this->fail('Project history must prevent deletion of a referenced record.');
        } catch (QueryException) {
            $this->assertModelExists($record);
            $this->assertModelExists($project);
        }
    }

    public function test_deleting_a_project_does_not_delete_its_client_or_category(): void
    {
        $project = Project::factory()->create();
        $client = $project->client;
        $category = $project->category;
        $project->delete();

        $this->assertModelExists($client);
        $this->assertModelExists($category);
        $this->assertModelMissing($project);
    }

    public static function budgets(): array
    {
        return ['zero' => ['0', '0.00'], 'whole' => ['45000', '45000.00'], 'fraction' => ['12345.67', '12345.67'], 'smallest fraction' => ['0.01', '0.01'], 'column maximum' => ['9999999999.99', '9999999999.99']];
    }

    public static function statuses(): array
    {
        return array_map(fn (ProjectStatus $status) => [$status], ProjectStatus::cases());
    }

    public static function foreignKeys(): array
    {
        return ['client' => ['client_id'], 'category' => ['category_id']];
    }

    public static function parents(): array
    {
        return ['client' => ['client'], 'category' => ['category']];
    }
}
