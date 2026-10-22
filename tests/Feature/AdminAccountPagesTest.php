<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ManagesAccounts;
use Tests\TestCase;

class AdminAccountPagesTest extends TestCase
{
    use ManagesAccounts, RefreshDatabase;

    public function test_admin_pages_are_read_only_and_use_safe_explicit_projections_with_real_counts(): void
    {
        $admin = $this->accountAdmin();
        $target = User::factory()->create(['name' => 'Visible account', 'avatar_path' => 'users/99/private-secret.webp', 'remember_token' => 'private-remember-token']);
        $project = Project::factory()->create(['client_id' => $target->id, 'title' => 'PRIVATE-PROJECT-BRIEF']);
        $before = $target->fresh()->getAttributes();
        $this->get(route('admin.accounts.index'))->assertOk()->assertSeeText($target->email)->assertDontSee('private-secret')->assertDontSee($target->password)->assertDontSee('private-remember-token');
        Volt::test('admin.accounts.index')->assertViewHas('accounts', function ($rows) {
            foreach ($rows as $row) {
                foreach (['password', 'remember_token', 'avatar_path', 'suspension_reason'] as $field) {
                    $this->assertArrayNotHasKey($field, $row->getAttributes());
                }
            }

            return true;
        });
        $this->get(route('admin.accounts.show', $target->id))->assertOk()->assertSeeText('Owned projects')->assertSeeText('No administrative status changes')->assertDontSee($project->title)->assertDontSee('private-secret')->assertDontSee($target->password);
        Volt::test('admin.accounts.show', ['account' => $target->id])->assertViewHas('account', fn ($row) => $row->projects_count === 1 && ! array_key_exists('password', $row->getAttributes()) && ! array_key_exists('avatar_path', $row->getAttributes()));
        Volt::test('admin.accounts.show', ['account' => $target->id])->call('prepareStatus')->assertSet('confirming', true)->assertSet('targetName', $target->name)->assertSeeText('Internal administrative reason')->assertSeeText('Not shown to the affected account owner.');
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_action_logs', 0);
        $this->get(route('client.projects.show', $project))->assertForbidden();
        $this->get(route('account.photo', $target))->assertForbidden();
    }

    #[DataProvider('deniedRoles')]
    public function test_guests_and_other_roles_cannot_access_list_detail_or_direct_components(?string $role): void
    {
        $target = User::factory()->create();
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }
        foreach ([route('admin.accounts.index'), route('admin.accounts.show', $target->id)] as $url) {
            $response = $this->get($url);
            $role === null ? $response->assertRedirect(route('login')) : $response->assertForbidden();
        }
        if ($role !== null) {
            Volt::test('admin.accounts.index')->assertForbidden();
            Volt::test('admin.accounts.show', ['account' => $target->id])->assertForbidden();
            $this->get(route($role.'.dashboard'))->assertDontSee('href="'.route('admin.accounts.index').'"', false);
        }
    }

    public function test_search_role_status_pagination_reset_and_deterministic_ordering(): void
    {
        $this->accountAdmin();
        $ids = [];
        for ($i = 1; $i <= 24; $i++) {
            $ids[] = User::factory()->create(['name' => sprintf('Filter person %02d', $i), 'email' => sprintf('filter%02d@example.test', $i), 'role' => $i <= 12 ? 'client' : 'freelancer', 'suspended_at' => $i <= 6 ? now() : null])->id;
        }
        $page = Volt::test('admin.accounts.index')->set('search', 'Filter person')->assertViewHas('accounts', fn ($rows) => $rows->total() === 24 && $rows->modelKeys() === array_slice($ids, 0, 10));
        $page->call('setPage', 2)->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === array_slice($ids, 10, 10))->set('role', 'client')->assertSet('paginators.page', 1)->set('status', 'suspended')->assertViewHas('accounts', fn ($rows) => $rows->total() === 6)->set('search', 'filter01@')->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$ids[0]])->call('clearFilters')->assertSet('paginators.page', 1)->assertSet('role', 'all')->assertSet('status', 'all')->assertSet('search', '');
        $this->get(route('admin.accounts.index', ['search' => 'filter', 'role' => 'freelancer', 'status' => 'active', 'page' => 2]))->assertOk()->assertSeeText('Filter person 23')->assertSeeText('Filter person 24')->assertDontSeeText('Filter person 13');
        $literal = User::factory()->create(['name' => '100% exact_match']);
        User::factory()->create(['name' => '100 percent exact match']);
        Volt::test('admin.accounts.index')->set('search', '100%')->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$literal->id])->set('search', 'exact_')->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$literal->id]);
    }

    #[DataProvider('invalidQueries')]
    public function test_list_query_parameters_are_bounded(string $field, mixed $value): void
    {
        $this->accountAdmin();
        $this->get(route('admin.accounts.index', [$field => $value]))->assertUnprocessable();
    }

    public function test_confirmation_validation_retains_input_and_history_is_escaped_private_and_paginated(): void
    {
        $this->accountAdmin();
        $target = User::factory()->create();
        $page = Volt::test('admin.accounts.show', ['account' => $target->id])->call('prepareStatus')->set('reason', '!!!')->call('confirmStatus')->assertHasErrors(['reason'])->assertSet('reason', '!!!')->assertSet('confirming', true);
        $reason = "<script>alert('reason')</script>\nInternal follow up.";
        $page->set('reason', $reason)->call('confirmStatus')->assertHasNoErrors()->assertSet('confirming', false)->assertSee(e($reason), false)->assertDontSee($reason, false)->assertSeeText('Account suspended.')->assertSee('aria-live="polite"', false);
        $log = AdminActionLog::sole();
        $this->get(route('admin.accounts.show', $target->id))->assertOk()->assertDontSee($log->action_id)->assertDontSee($log->request_hash)->assertSeeText('Internal administrative reason');
        for ($i = 0; $i < 10; $i++) {
            $this->changeAccount($target, $i % 2 === 1, ['reason' => 'Administrative history entry '.$i]);
        }
        Volt::test('admin.accounts.show', ['account' => $target->id])->assertViewHas('history', fn ($history) => $history->total() === 11 && $history->count() === 10)->call('setPage', 2)->assertViewHas('history', fn ($history) => $history->count() === 1);
    }

    #[DataProvider('staleAdminStates')]
    public function test_stale_admin_components_recheck_persisted_role_and_status(string $field, mixed $value): void
    {
        $admin = $this->accountAdmin();
        $target = User::factory()->create();
        $page = Volt::test('admin.accounts.show', ['account' => $target->id])->call('prepareStatus')->set('reason', 'Keep this reason.');
        DB::table('users')->where('id', $admin->id)->update([$field => $value]);
        $page->call('confirmStatus')->assertForbidden();
        $this->assertTrue($target->fresh()->isActive());
        $this->assertDatabaseCount('admin_action_logs', 0);
    }

    #[DataProvider('lockedFields')]
    public function test_confirmation_identities_cannot_be_forged(string $field, mixed $value): void
    {
        $this->accountAdmin();
        $target = User::factory()->create();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('admin.accounts.show', ['account' => $target->id])->set($field, $value);
    }

    public function test_admin_accounts_have_no_status_controls_and_dashboard_counts_and_links_are_real(): void
    {
        $admin = $this->accountAdmin();
        User::factory()->create(['suspended_at' => now()]);
        $this->get(route('admin.accounts.show', $admin->id))->assertOk()->assertSeeText('Administrator accounts are protected.')->assertDontSee('wire:click="prepareStatus"', false);
        Volt::test('admin.accounts.show', ['account' => $admin->id])->call('prepareStatus')->assertForbidden();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('href="'.route('admin.accounts.index').'"', false)->assertViewHas('accountStatusCounts', fn ($counts) => (int) $counts->active === 1 && (int) $counts->suspended === 1);
        $this->get(route('admin.accounts.index', ['search' => 'no-such-user']))->assertOk()->assertSeeText('No accounts match this page or filter.');
        $this->post(route('admin.accounts.show', $admin))->assertMethodNotAllowed();
    }

    public static function deniedRoles(): array
    {
        return [[null], ['client'], ['freelancer']];
    }

    public static function invalidQueries(): array
    {
        return [['search', str_repeat('a', 101)], ['search', ['bad']], ['role', 'superadmin'], ['role', ['bad']], ['status', 'blocked'], ['status', ['bad']], ['page', 0], ['page', 10001], ['page', 'wrong']];
    }

    public static function staleAdminStates(): array
    {
        return [['role', 'client'], ['suspended_at', '2026-11-01 12:00:00']];
    }

    public static function lockedFields(): array
    {
        return [['accountId', 999999], ['suspend', true], ['revision', 'forged'], ['actionId', 'forged'], ['targetName', 'Forged'], ['targetEmail', 'forged@example.test']];
    }
}
