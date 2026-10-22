<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountSessions
{
    public function revoke(User $account): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }
        $connection = DB::connection(config('session.connection'));
        $delete = fn () => $connection->table(config('session.table'))->where('user_id', $account->id)->delete();
        if ($connection->getName() === $account->getConnection()->getName()) {
            $delete();
        } else {
            // Different session databases cannot participate in the originating transaction.
            // Persistent access-version checks remain authoritative for every backend.
            $account->getConnection()->afterCommit($delete);
        }
    }
}
