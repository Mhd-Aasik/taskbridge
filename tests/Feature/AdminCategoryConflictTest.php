<?php

namespace Tests\Feature;

use App\Actions\Categories\ManageCategory;
use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ManagesCategories;
use Tests\TestCase;

class AdminCategoryConflictTest extends TestCase
{
    use ManagesCategories, RefreshDatabase;

    public function test_simulated_committed_action_winner_returns_original_receipt_without_duplicate_creation(): void
    {
        $admin = $this->categoryAdmin();
        $token = (string) Str::uuid();
        $hash = hash('sha256', json_encode([AdminAction::CategoryCreated->value, null, ['name' => 'Concurrent catalogue', 'description' => null], '', []]));
        AdminActionLog::creating(function () use ($admin, $token, $hash): never {
            DB::afterRollBack(function () use ($admin, $token, $hash): void {
                // A simulated durable competitor after rollback, not actual parallel MySQL.
                $category = Category::create(['name' => 'Concurrent catalogue']);
                DB::table('admin_action_logs')->insert(['actor_id' => $admin->id, 'subject_type' => $category->getMorphClass(), 'subject_id' => $category->id, 'action' => AdminAction::CategoryCreated->value,
                    'before_values' => json_encode([]), 'after_values' => json_encode($category->only(['name', 'slug', 'description', 'is_active'])), 'occurred_at' => '2026-10-31 10:00:00', 'action_id' => $token, 'request_hash' => $hash, 'created_at' => '2026-10-31 10:00:00', 'updated_at' => '2026-10-31 10:00:00']);
            });
            throw $this->conflict('admin_action_logs.action_id');
        });
        $result = app(ManageCategory::class)->create(['name' => 'Concurrent catalogue'], $token);
        $this->assertTrue($result['replayed']);
        $this->assertFalse($result['changed']);
        $this->assertSame('2026-10-31 10:00:00', AdminActionLog::sole()->occurred_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('admin_action_logs', 1);
    }

    public function test_simulated_slug_competitor_returns_a_clear_collision_and_preserves_the_winner(): void
    {
        $this->categoryAdmin();
        Category::creating(function (): never {
            DB::afterRollBack(fn () => DB::table('categories')->insert(['name' => 'Other administrator category', 'slug' => 'concurrent-slug', 'description' => 'Preserve this', 'is_active' => false]));
            throw $this->conflict('categories.slug');
        });
        try {
            app(ManageCategory::class)->create(['name' => 'Concurrent slug'], (string) Str::uuid());
            $this->fail('The slug conflict must be returned clearly.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
        $this->assertDatabaseHas('categories', ['name' => 'Other administrator category', 'is_active' => false]);
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    #[DataProvider('conflicts')]
    public function test_missing_receipts_and_unrelated_database_failures_propagate(string $columns): void
    {
        $this->categoryAdmin();
        $failure = $this->conflict($columns);
        AdminActionLog::creating(function () use ($failure): never {
            throw $failure;
        });
        try {
            app(ManageCategory::class)->create(['name' => 'Rollback category'], (string) Str::uuid());
            $this->fail('Unknown or missing-winner errors must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    private function conflict(string $columns): UniqueConstraintViolationException
    {
        $pdo = new PDOException('UNIQUE constraint failed: '.$columns);
        $pdo->errorInfo = ['23000', 19, $pdo->getMessage()];

        return new UniqueConstraintViolationException('sqlite', 'insert simulated category conflict', [], $pdo);
    }

    public static function conflicts(): array
    {
        return [['admin_action_logs.action_id'], ['admin_action_logs.id'], ['another_table.unique_column']];
    }
}
