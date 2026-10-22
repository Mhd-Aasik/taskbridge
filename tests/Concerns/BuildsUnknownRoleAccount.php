<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait BuildsUnknownRoleAccount
{
    private function unknownRoleAccount(): User
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        // Simulate a legacy/corrupt stored role; restore CHECK enforcement immediately.
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            return User::factory()->create(['role' => 'unknown'])->fresh();
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
    }
}
