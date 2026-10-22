<?php

namespace Tests\Concerns;

use App\Actions\Accounts\ChangeAccountStatus;
use App\Models\User;
use App\Support\AccountManagement;
use Illuminate\Support\Str;

trait ManagesAccounts
{
    private function accountAdmin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        return $admin;
    }

    private function changeAccount(User $account, bool $suspend, array $options = []): array
    {
        return app(ChangeAccountStatus::class)->change($account->id, $suspend, ['reason' => array_key_exists('reason', $options) ? $options['reason'] : 'Account status checked by administration.'], $options['revision'] ?? AccountManagement::version($account->fresh()), $options['token'] ?? (string) Str::uuid(), $options['confirmed'] ?? true);
    }
}
