<?php

namespace Tests\Feature;

use App\Actions\Accounts\ChangeAccountStatus;
use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\FreelancerProfile;
use App\Models\User;
use App\Support\AccountManagement;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ManagesAccounts;
use Tests\TestCase;

class AdminAccountActionsTest extends TestCase
{
    use ManagesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'));
    }

    public function test_status_changes_are_audited_utc_server_owned_and_preserve_account_and_domain_data(): void
    {
        $admin = $this->accountAdmin();
        $target = User::factory()->create(['role' => User::ROLE_FREELANCER, 'remember_token' => 'original-token']);
        $profile = FreelancerProfile::factory()->create(['user_id' => $target->id, 'is_public' => false])->fresh();
        $before = $target->fresh()->getAttributes();
        $profileBefore = $profile->getAttributes();
        $result = app(ChangeAccountStatus::class)->change($target->id, true, ['reason' => "  Verified policy concern.\nFollow up.  ", 'role' => 'admin', 'suspended_by' => 999999, 'access_version' => 999, 'password' => 'secret'], AccountManagement::version($target), (string) Str::uuid(), true);
        $suspended = $target->fresh();
        $this->assertTrue($result['changed']);
        $this->assertFalse($suspended->isActive());
        $this->assertSame($admin->id, $suspended->suspended_by);
        $this->assertEquals(now('UTC'), $suspended->suspended_at);
        $this->assertSame("Verified policy concern.\nFollow up.", $suspended->suspension_reason);
        $this->assertSame(1, $suspended->access_version);
        $this->assertNotSame('original-token', $suspended->remember_token);
        $this->assertTrue($suspended->suspendedBy->is($admin));
        $this->assertTrue($admin->suspendedAccounts()->sole()->is($target));
        foreach (['name', 'email', 'password', 'role', 'avatar_path', 'created_at'] as $field) {
            $this->assertSame($before[$field] ?? null, $suspended->getRawOriginal($field));
        }
        $log = AdminActionLog::sole();
        $this->assertSame(AdminAction::AccountSuspended, $log->action);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertTrue($log->subject->is($target));
        $this->assertSame(['status' => 'active', 'suspended_at' => null], $log->before_values);
        $this->assertSame(['status' => 'suspended', 'suspended_at' => '2026-11-03 12:00:00', 'reason' => "Verified policy concern.\nFollow up."], $log->after_values);
        $this->assertStringNotContainsString('secret', $log->toJson());
        $this->travel(1)->hour();
        $reactivated = $this->changeAccount($target, false, ['reason' => 'Concern resolved.'])['account'];
        $this->assertTrue($reactivated->isActive());
        $this->assertNull($reactivated->suspended_at);
        $this->assertNull($reactivated->suspended_by);
        $this->assertNull($reactivated->suspension_reason);
        $this->assertSame(2, $reactivated->access_version);
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertSame('Concern resolved.', AdminActionLog::latest('id')->first()->after_values['reason']);
        $this->assertDatabaseCount('admin_action_logs', 2);
        $this->assertDatabaseCount('notifications', 0);
    }

    #[DataProvider('invalidReasons')]
    public function test_invalid_reasons_leave_everything_unchanged(mixed $reason): void
    {
        $this->accountAdmin();
        $target = User::factory()->create()->fresh();
        $before = $target->getAttributes();
        try {
            $this->changeAccount($target, true, ['reason' => $reason]);
            $this->fail('A meaningful reason is required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    public function test_maximum_length_reason_confirmation_and_unchanged_requests(): void
    {
        $this->accountAdmin();
        $target = User::factory()->create()->fresh();
        try {
            $this->changeAccount($target, true, ['confirmed' => false]);
            $this->fail('Confirmation is required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmed', $e->errors());
        }
        $before = $target->getAttributes();
        $this->assertFalse($this->changeAccount($target, false)['changed']);
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 0);
        $this->changeAccount($target, true, ['reason' => str_repeat('a', 1000)]);
        $this->assertSame(1000, strlen($target->fresh()->suspension_reason));
    }

    public function test_retries_preserve_original_outcome_and_time_without_overwriting_later_state(): void
    {
        $this->accountAdmin();
        $target = User::factory()->create()->fresh();
        $options = ['revision' => AccountManagement::version($target), 'token' => (string) Str::uuid()];
        $first = $this->changeAccount($target, true, $options);
        $original = $first['log']->fresh()->getAttributes();
        $this->changeAccount($target, false);
        $before = $target->fresh()->getAttributes();
        $this->travel(1)->day();
        $retry = $this->changeAccount($target, true, $options);
        $this->assertTrue($retry['replayed']);
        $this->assertSame($original, $retry['log']->getAttributes());
        $this->assertSame($before, $target->fresh()->getAttributes());
        try {
            $this->changeAccount($target, true, $options + ['reason' => 'Different reason.']);
            $this->fail('A token cannot be reused with a changed payload.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account', $e->errors());
        }
        $this->accountAdmin();
        try {
            $this->changeAccount($target, true, $options);
            $this->fail('Another actor cannot reuse the receipt.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account', $e->errors());
        }
        $this->assertDatabaseCount('admin_action_logs', 2);
    }

    public function test_stale_and_aba_requests_cannot_overwrite_current_status_or_identity(): void
    {
        $this->accountAdmin();
        $target = User::factory()->create();
        $revision = AccountManagement::version($target);
        $this->changeAccount($target, true);
        $this->changeAccount($target, false);
        try {
            $this->changeAccount($target, true, ['revision' => $revision]);
            $this->fail('An old revision must remain stale.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account', $e->errors());
        }
        $revision = AccountManagement::version($target->fresh());
        $target->fresh()->update(['email' => 'changed@example.test']);
        try {
            $this->changeAccount($target, true, ['revision' => $revision]);
            $this->fail('Confirm the current identity.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account', $e->errors());
        }
        $this->assertTrue($target->fresh()->isActive());
        $this->assertDatabaseCount('admin_action_logs', 2);
    }

    #[DataProvider('rollbackOperations')]
    public function test_audit_failure_rolls_back_status_remember_token_and_targeted_sessions(bool $suspend): void
    {
        $this->accountAdmin();
        $target = User::factory()->create();
        if (! $suspend) {
            $this->changeAccount($target, true);
        }
        $before = $target->fresh()->getAttributes();
        $logCount = AdminActionLog::count();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'preserve-session', 'user_id' => $target->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        AdminActionLog::creating(fn () => false);
        try {
            $this->changeAccount($target, $suspend);
            $this->fail('Audit failure must roll back the status.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('audit event', $e->getMessage());
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseHas('sessions', ['id' => 'preserve-session']);
        $this->assertDatabaseCount('admin_action_logs', $logCount);
    }

    #[DataProvider('deniedActors')]
    public function test_non_admin_or_suspended_admin_is_denied(string $role, bool $suspended): void
    {
        $actor = User::factory()->create(['role' => $role, 'suspended_at' => $suspended ? now() : null]);
        $target = User::factory()->create();
        $this->actingAs($actor);
        $this->expectException(HttpException::class);
        try {
            $this->changeAccount($target, true);
        } catch (AuthorizationException $exception) {
            abort(403);
        }
    }

    public function test_admin_targets_and_self_are_protected(): void
    {
        $admin = $this->accountAdmin();
        $other = User::factory()->create(['role' => User::ROLE_ADMIN]);
        foreach ([$admin, $other] as $target) {
            try {
                $this->changeAccount($target, true);
                $this->fail('Administrators cannot be suspended here.');
            } catch (AuthorizationException) {
                $this->assertTrue($target->fresh()->isActive());
            }
        }
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    public function test_protected_fields_and_metadata_are_not_public_mass_assignment_or_serialization(): void
    {
        $user = User::factory()->create()->fresh();
        $before = $user->getAttributes();
        $fields = ['suspended_at' => now(), 'suspended_by' => 999999, 'suspension_reason' => 'Forged', 'access_version' => 999];
        $user->fill($fields)->save();
        foreach ($fields as $field => $value) {
            $this->assertFalse($user->isFillable($field));
            $this->assertArrayNotHasKey($field, $user->toArray());
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public static function invalidReasons(): array
    {
        return [[null], [''], [" \n\u{2003} "], ['!!!'], [str_repeat('a', 1001)], [[]]];
    }

    public static function rollbackOperations(): array
    {
        return [[true], [false]];
    }

    public static function deniedActors(): array
    {
        return [[User::ROLE_CLIENT, false], [User::ROLE_FREELANCER, false], [User::ROLE_ADMIN, true]];
    }
}
