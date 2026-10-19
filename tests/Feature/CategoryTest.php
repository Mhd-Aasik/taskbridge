<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_have_normalised_slugs_optional_descriptions_and_an_active_default(): void
    {
        $category = Category::query()->create(['name' => 'Web Development']);

        $this->assertSame('web-development', $category->fresh()->slug);
        $this->assertNull($category->fresh()->description);
        $this->assertTrue($category->fresh()->is_active);

        $category->update(['name' => 'Renamed category', 'is_active' => false]);
        $this->assertSame('web-development', $category->fresh()->slug);
        $this->assertFalse($category->fresh()->is_active);

        $category->update(['slug' => ' Custom SLUG ']);
        $this->assertSame('custom-slug', $category->fresh()->slug);
    }

    public function test_category_slugs_are_unique_after_normalisation(): void
    {
        Category::factory()->create(['slug' => 'web-development']);

        $this->expectException(QueryException::class);
        Category::factory()->create(['slug' => 'Web Development']);
    }

    public function test_reference_seeding_is_idempotent_without_demo_data(): void
    {
        $this->seed(CategorySeeder::class);
        $initial = Category::query()->orderBy('id')->get()->toArray();
        $this->travel(1)->hour();
        $this->seed(CategorySeeder::class);

        $this->assertSame($initial, Category::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('categories', 8);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('skills', 0);
        $this->assertDatabaseCount('freelancer_profiles', 0);
    }

    public function test_seeding_preserves_administrator_edits_custom_categories_and_project_references(): void
    {
        $existing = Category::factory()->create([
            'name' => 'Our web services',
            'slug' => 'web-development',
            'description' => 'Administrator description',
            'is_active' => false,
        ]);
        $custom = Category::factory()->create(['name' => 'Specialist Research']);
        $project = Project::factory()->for($existing)->create();
        $before = $existing->fresh()->getAttributes();
        $customBefore = $custom->fresh()->getAttributes();
        $projectBefore = $project->fresh()->getAttributes();

        $this->travel(1)->hour();
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame($customBefore, $custom->fresh()->getAttributes());
        $this->assertSame($projectBefore, $project->fresh()->getAttributes());
        $this->assertDatabaseCount('categories', 9);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_default_seeder_adds_reference_data_without_creating_accounts_or_projects(): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('freelancer_profiles', 0);
        $this->assertDatabaseCount('skills', 8);
        $this->assertDatabaseCount('categories', 8);
    }
}
