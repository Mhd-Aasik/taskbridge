<?php

namespace Tests\Feature;

use App\Actions\Accounts\ChangeAccountStatus;
use App\Models\AdminActionLog;
use App\Models\User;
use App\Services\AccountSessions;
use App\Support\AccountManagement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ManagesAccounts;
use Tests\TestCase;

class AdminAccountConflictTest extends TestCase
{
    use ManagesAccounts, RefreshDatabase;

    public function test_simulated_committed_retry_winner_is_returned_without_reapplying_the_transition(): void
    {
        $this->accountAdmin();
        $target = User::factory()->create()->fresh();
        $revision = AccountManagement::version($target);
        $token = (string) Str::uuid();
        AdminActionLog::creating(function (AdminActionLog $log) use ($target): never {
            $state = $target->fresh()->only(['suspended_at', 'suspended_by', 'suspension_reason', 'access_version', 'remember_token']);
            $row = $log->getAttributes();
            unset($row['id']);
            $row['created_at'] = $row['updated_at'] = '2026-11-03 12:00:00';
            $row['occurred_at'] = '2026-11-03 12:00:00';
            DB::afterRollBack(function () use ($target, $state, $row): void {
                // Simulated committed contender after our rollback, not parallel MySQL.
                DB::table('users')->where('id', $target->id)->update($state);
                DB::table('admin_action_logs')->insert($row);
            });
            throw $this->conflict('admin_action_logs.action_id');
        });
        $result = app(ChangeAccountStatus::class)->change($target->id, true, ['reason' => 'Verified account concern.'], $revision, $token, true);
        $this->assertTrue($result['replayed']);
        $this->assertFalse($result['changed']);
        $this->assertSame(1, $target->fresh()->access_version);
        $this->assertSame('2026-11-03 12:00:00', $result['log']->occurred_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('admin_action_logs', 1);
    }

    #[DataProvider('constraintErrors')]
    public function test_missing_retry_receipts_and_unrelated_database_errors_are_not_hidden(string $columns): void
    {
        $this->accountAdmin();
        $target = User::factory()->create()->fresh();
        $before = $target->getAttributes();
        $failure = $this->conflict($columns);
        AdminActionLog::creating(function () use ($failure): never {
            throw $failure;
        });
        try {
            $this->changeAccount($target, true);
            $this->fail('The original failure must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    public function test_session_revocation_failure_rolls_back_status_token_and_audit(): void
    {
        $this->accountAdmin();
        $target = User::factory()->create()->fresh();
        $before = $target->getAttributes();
        $this->mock(AccountSessions::class, fn ($mock) => $mock->shouldReceive('revoke')->once()->andThrow(new RuntimeException('Session store unavailable.')));
        try {
            $this->changeAccount($target, true);
            $this->fail('A same-transaction failure must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Session store unavailable.', $exception->getMessage());
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    public function test_user_save_veto_and_enclosing_rollback_leave_no_false_audit_history(): void
    {
        $this->accountAdmin();
        $target = User::factory()->create()->fresh();
        $before = $target->getAttributes();
        try {
            DB::transaction(function () use ($target): void {
                $this->changeAccount($target, true);
                throw new RuntimeException('Cancel outer work.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Cancel outer work.', $exception->getMessage());
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        User::updating(fn () => false);
        try {
            $this->changeAccount($target, true);
            $this->fail('A vetoed save cannot record success.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not be saved', $exception->getMessage());
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    private function conflict(string $columns): UniqueConstraintViolationException
    {
        $pdo = new PDOException('UNIQUE constraint failed: '.$columns);
        $pdo->errorInfo = ['23000', 19, $pdo->getMessage()];

        return new UniqueConstraintViolationException('sqlite', 'insert simulated account conflict', [], $pdo);
    }

    public static function constraintErrors(): array
    {
        return [['admin_action_logs.action_id'], ['admin_action_logs.id'], ['another_table.unique_column']];
    }
}
