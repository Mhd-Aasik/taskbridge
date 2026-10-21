<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\Category;
use App\Models\User;
use App\Services\AdminActionLogs;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminActionLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_casts_relationships_utc_time_and_guarded_metadata(): void
    {
        $log = AdminActionLog::factory()->create(['occurred_at' => '2026-11-01 00:15:00+05:30'])->fresh();
        $this->assertTrue($log->actor->isAdmin());
        $this->assertTrue($log->actor->adminActionLogs()->sole()->is($log));
        $this->assertInstanceOf(Category::class, $log->subject);
        $this->assertSame(AdminAction::CategoryCreated, $log->action);
        $this->assertIsArray($log->before_values);
        $this->assertIsArray($log->after_values);
        $this->assertInstanceOf(CarbonImmutable::class, $log->occurred_at);
        $this->assertSame('2026-10-31 18:45:00', $log->getRawOriginal('occurred_at'));
        foreach (['actor_id', 'subject_type', 'subject_id', 'action', 'before_values', 'after_values', 'occurred_at', 'action_id', 'request_hash', 'created_at', 'updated_at'] as $field) {
            $this->assertFalse($log->isFillable($field));
        }
        $this->assertArrayNotHasKey('action_id', $log->toArray());
        $this->assertArrayNotHasKey('request_hash', $log->toArray());
    }

    #[DataProvider('immutableFields')]
    public function test_action_logs_are_append_only(string $field, mixed $value): void
    {
        $log = AdminActionLog::factory()->create()->fresh();
        $before = $log->getAttributes();
        try {
            $log->forceFill([$field => $value])->save();
            $this->fail('Audit events cannot be rewritten.');
        } catch (LogicException) {
            $this->assertSame($before, $log->fresh()->getAttributes());
        }
    }

    public function test_action_logs_cannot_be_deleted_by_an_application_model_action(): void
    {
        $log = AdminActionLog::factory()->create();
        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_action_identifier_is_database_unique(): void
    {
        $log = AdminActionLog::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        AdminActionLog::factory()->create(['action_id' => $log->action_id]);
    }

    public function test_actor_foreign_key_restricts_deletion_and_rejects_missing_accounts(): void
    {
        $log = AdminActionLog::factory()->create();
        try {
            DB::table('users')->where('id', $log->actor_id)->delete();
            $this->fail('The actor cannot be erased.');
        } catch (QueryException) {
            $this->assertModelExists($log->actor);
        }
        $this->expectException(QueryException::class);
        AdminActionLog::factory()->create(['actor_id' => 999999]);
    }

    public function test_service_requires_an_origin_transaction_and_rejects_non_whitelisted_audit_data(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $category = Category::factory()->create();
        try {
            app(AdminActionLogs::class)->record($admin, AdminAction::CategoryUpdated, $category, ['name' => 'Old', 'description' => null, 'password' => 'secret'], ['name' => 'New', 'description' => null], (string) Str::uuid(), hash('sha256', 'request'));
            $this->fail('Unrelated fields must never be logged.');
        } catch (LogicException) {
            $this->assertDatabaseCount('admin_action_logs', 0);
        }
        // RefreshDatabase already has a surrounding transaction; an explicitly separate SQLite connection has none.
        config(['database.connections.audit_unstarted' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $category->setConnection('audit_unstarted');
        try {
            app(AdminActionLogs::class)->record($admin, AdminAction::CategoryCreated, $category, [], ['name' => 'Safe', 'slug' => 'safe', 'description' => null, 'is_active' => true], (string) Str::uuid(), hash('sha256', 'request'));
            $this->fail('An originating transaction is required.');
        } catch (LogicException) {
            $this->assertDatabaseCount('admin_action_logs', 0);
        } finally {
            DB::purge('audit_unstarted');
        }
    }

    public static function immutableFields(): array
    {
        return [['actor_id', 999999], ['subject_id', 999999], ['subject_type', User::class], ['action', AdminAction::CategoryUpdated], ['before_values', ['name' => 'Changed']], ['after_values', []], ['occurred_at', '2030-01-01'], ['action_id', '00000000-0000-4000-8000-000000000000'], ['request_hash', 'changed'], ['created_at', '2030-01-01']];
    }
}
