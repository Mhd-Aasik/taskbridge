<?php

namespace Tests\Feature;

use App\Actions\Categories\ManageCategory;
use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Support\CategoryManagement;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ManagesCategories;
use Tests\TestCase;

class AdminCategoryActionsTest extends TestCase
{
    use ManagesCategories, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));
    }

    public function test_creation_resolves_normalized_slug_active_default_actor_and_safe_audit_values(): void
    {
        $admin = $this->categoryAdmin();
        $token = (string) Str::uuid();
        $result = app(ManageCategory::class)->create(['name' => '  Audio & Sound Design  ', 'description' => "  A clear brief.\nSound work.  ", 'slug' => 'forged-slug', 'is_active' => false, 'actor_id' => 999999, 'occurred_at' => '2030-01-01', 'password' => 'do-not-log'], $token);
        $category = $result['category']->fresh();
        $this->assertTrue($result['changed']);
        $this->assertSame('Audio & Sound Design', $category->name);
        $this->assertSame('audio-sound-design', $category->slug);
        $this->assertSame("A clear brief.\nSound work.", $category->description);
        $this->assertTrue($category->is_active);
        $log = AdminActionLog::sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(AdminAction::CategoryCreated, $log->action);
        $this->assertSame([], $log->before_values);
        $this->assertSame($category->only(['name', 'slug', 'description', 'is_active']), $log->after_values);
        $this->assertSame($token, $log->action_id);
        $this->assertEquals(now('UTC'), $log->occurred_at);
        $this->assertStringNotContainsString('do-not-log', $log->toJson());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_validation_failures_create_neither_categories_nor_audit_events(string $field, mixed $value): void
    {
        $this->categoryAdmin();
        try {
            app(ManageCategory::class)->create(array_replace(['name' => 'A useful category', 'description' => null], [$field => $value]), (string) Str::uuid());
            $this->fail('Invalid details must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    public function test_maximum_field_lengths_and_blank_optional_description_are_supported(): void
    {
        $this->categoryAdmin();
        $result = app(ManageCategory::class)->create(['name' => str_repeat('a', 100), 'description' => str_repeat('b', 1000)], (string) Str::uuid());
        $this->assertSame(100, strlen($result['category']->name));
        $this->assertSame(1000, strlen($result['category']->description));
        $edited = $this->categoryEdit($result['category'], ['description' => " \n\u{2003} "]);
        $this->assertNull($edited['category']->fresh()->description);
    }

    public function test_normalized_slug_collisions_are_clear_without_replacing_existing_records(): void
    {
        $this->categoryAdmin();
        $existing = Category::factory()->create(['name' => 'Web Design', 'slug' => 'web-design', 'is_active' => false])->fresh();
        $before = $existing->getAttributes();
        try {
            app(ManageCategory::class)->create(['name' => 'Web-Design'], (string) Str::uuid());
            $this->fail('The collision must be reported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    public function test_descriptive_edit_preserves_slug_activation_and_project_references(): void
    {
        $this->categoryAdmin();
        $category = Category::factory()->create(['is_active' => false])->fresh();
        $project = Project::factory()->create(['category_id' => $category->id])->fresh();
        $before = $project->getAttributes();
        $originalDetails = $category->only(['name', 'description']);
        $saved = $this->categoryEdit($category, ['name' => 'Updated reference name', 'description' => 'Updated description', 'slug' => 'forged', 'is_active' => true, 'id' => 999999])['category']->fresh();
        $this->assertSame($category->slug, $saved->slug);
        $this->assertFalse($saved->is_active);
        $this->assertSame($before, $project->fresh()->getAttributes());
        $log = AdminActionLog::sole();
        $this->assertSame(AdminAction::CategoryUpdated, $log->action);
        $this->assertSame($originalDetails, $log->before_values);
        $this->assertSame(['name' => 'Updated reference name', 'description' => 'Updated description'], $log->after_values);
    }

    public function test_edit_opened_before_deactivation_can_save_details_without_reactivating(): void
    {
        $this->categoryAdmin();
        $category = Category::factory()->create();
        $version = CategoryManagement::version($category);
        $this->categoryActivation($category, false);
        $saved = $this->categoryEdit($category, ['name' => 'New descriptive label', 'is_active' => true], $version)['category'];
        $this->assertFalse($saved->is_active);
        $this->assertDatabaseCount('admin_action_logs', 2);
    }

    public function test_conflicting_and_aba_descriptive_saves_are_refused_with_original_input_preserved(): void
    {
        $this->categoryAdmin();
        $category = Category::factory()->create();
        $version = CategoryManagement::version($category);
        $page = Volt::test('admin.categories')->call('editCategory', $category->id)->set('name', 'My retained edit');
        $this->categoryEdit($category, ['name' => 'Another administrator edit']);
        $page->call('save')->assertHasErrors(['category'])->assertSet('name', 'My retained edit');
        $this->categoryEdit($category->fresh(), ['name' => $category->name, 'description' => $category->description]);
        try {
            $this->categoryEdit($category, ['description' => 'Stale ABA attempt'], $version);
            $this->fail('The prior revision must not become fresh again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }
        $this->assertDatabaseCount('admin_action_logs', 2);
    }

    public function test_activation_is_confirmed_and_unchanged_operations_do_not_change_times_or_log_events(): void
    {
        $this->categoryAdmin();
        $category = Category::factory()->create()->fresh();
        $before = $category->getAttributes();
        $this->travel(1)->hour();
        $this->assertFalse($this->categoryEdit($category, [])['changed']);
        $this->assertFalse($this->categoryActivation($category, true)['changed']);
        $this->assertSame($before, $category->fresh()->getAttributes());
        try {
            $this->categoryActivation($category, false, confirmed: false);
            $this->fail('Confirmation is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmed', $exception->errors());
        }
        $this->assertDatabaseCount('admin_action_logs', 0);
        $this->categoryActivation($category, false);
        $this->assertSame(['is_active' => true], AdminActionLog::sole()->before_values);
        $this->assertSame(['is_active' => false], AdminActionLog::sole()->after_values);
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_stale_activation_and_changed_impact_counts_require_new_confirmation(): void
    {
        $this->categoryAdmin();
        $category = Category::factory()->create();
        $version = CategoryManagement::version($category, true);
        $impact = CategoryManagement::impact($category);
        Project::factory()->create(['category_id' => $category->id]);
        try {
            $this->categoryActivation($category, false, $version, $impact);
            $this->fail('Changed counts must be reviewed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('counts changed', $exception->errors()['category'][0]);
        }
        $this->categoryActivation($category, false);
        $this->categoryActivation($category, true);
        try {
            $this->categoryActivation($category, false, $version, CategoryManagement::impact($category));
            $this->fail('A stale activation must not overwrite a later reactivation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }
        $this->assertTrue($category->fresh()->is_active);
        $this->assertDatabaseCount('admin_action_logs', 2);
    }

    public function test_exact_retries_return_original_receipts_without_overwriting_later_changes(): void
    {
        $this->categoryAdmin();
        $token = (string) Str::uuid();
        $created = app(ManageCategory::class)->create(['name' => 'Original name'], $token)['category'];
        $this->categoryEdit($created, ['name' => 'Later name']);
        $retry = app(ManageCategory::class)->create(['name' => '  Original name  '], $token);
        $this->assertTrue($retry['replayed']);
        $this->assertSame('Later name', $retry['category']->name);
        $version = CategoryManagement::version($created->fresh(), true);
        $impact = CategoryManagement::impact($created);
        $toggleToken = (string) Str::uuid();
        $this->categoryActivation($created, false, $version, $impact, $toggleToken);
        $this->categoryActivation($created, true);
        $before = $created->fresh()->getAttributes();
        $logRows = AdminActionLog::orderBy('id')->get()->toArray();
        $this->travel(1)->hour();
        $replayed = $this->categoryActivation($created, false, $version, $impact, $toggleToken);
        $this->assertTrue($replayed['replayed']);
        $this->assertSame($before, $created->fresh()->getAttributes());
        $this->assertSame($logRows, AdminActionLog::orderBy('id')->get()->toArray());
    }

    public function test_changed_request_or_actor_cannot_reuse_a_completed_action_identifier(): void
    {
        $this->categoryAdmin();
        $token = (string) Str::uuid();
        app(ManageCategory::class)->create(['name' => 'Stable action'], $token);
        try {
            app(ManageCategory::class)->create(['name' => 'Different action'], $token);
            $this->fail('A changed payload cannot reuse a receipt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }
        $this->categoryAdmin();
        try {
            app(ManageCategory::class)->create(['name' => 'Stable action'], $token);
            $this->fail('Another actor cannot reuse a receipt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('admin_action_logs', 1);
    }

    #[DataProvider('operations')]
    public function test_audit_failure_rolls_back_every_kind_of_category_mutation(string $operation): void
    {
        $this->categoryAdmin();
        $category = Category::factory()->create(['is_active' => $operation !== 'activate'])->fresh();
        $before = $category->getAttributes();
        AdminActionLog::creating(fn () => false);
        try {
            match ($operation) {
                'create' => app(ManageCategory::class)->create(['name' => 'Rollback new category'], (string) Str::uuid()), 'edit' => $this->categoryEdit($category, ['name' => 'Rollback label']), 'activate' => $this->categoryActivation($category, true), default => $this->categoryActivation($category, false)
            };
            $this->fail('Category changes must not commit without their audit event.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('audit event', $exception->getMessage());
        }
        $this->assertSame($before, $category->fresh()->getAttributes());
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    public function test_enclosing_rollback_preserves_category_and_audit_history(): void
    {
        $this->categoryAdmin();
        $category = Category::factory()->create()->fresh();
        $before = $category->getAttributes();
        try {
            DB::transaction(function () use ($category): void {
                $this->categoryActivation($category, false);
                throw new RuntimeException('Abort outer transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort outer transaction', $exception->getMessage());
        }
        $this->assertSame($before, $category->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    #[DataProvider('wrongRoles')]
    public function test_non_admins_cannot_invoke_the_actions_directly(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->expectException(AuthorizationException::class);
        app(ManageCategory::class)->create(['name' => 'Forbidden'], (string) Str::uuid());
    }

    public function test_seed_repeats_preserve_administrative_edits_activation_logs_and_timestamps(): void
    {
        $this->seed(CategorySeeder::class);
        $this->categoryAdmin();
        $category = Category::where('slug', 'web-development')->sole();
        $this->categoryEdit($category, ['name' => 'Our specialised web work', 'description' => 'Admin maintained description']);
        $this->categoryActivation($category, false);
        $before = Category::orderBy('id')->get()->toArray();
        $logs = AdminActionLog::orderBy('id')->get()->toArray();
        $this->travel(2)->days();
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);
        $this->assertSame($before, Category::orderBy('id')->get()->toArray());
        $this->assertSame($logs, AdminActionLog::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('categories', 8);
        $this->assertDatabaseCount('projects', 0);
    }

    public static function invalidFields(): array
    {
        return [['name', null], ['name', ''], ['name', " \n\u{2003}"], ['name', '!!!'], ['name', '💡'], ['name', []], ['name', str_repeat('a', 101)], ['description', []], ['description', str_repeat('a', 1001)]];
    }

    public static function operations(): array
    {
        return [['create'], ['edit'], ['activate'], ['deactivate']];
    }

    public static function wrongRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER]];
    }
}
