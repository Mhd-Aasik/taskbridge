<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RuntimeException;

class AdminActionLogs
{
    /** Extend the enum/subject/field whitelist explicitly when introducing later Admin operations. */
    public function record(User $actor, AdminAction $action, Model $subject, array $before, array $after, string $actionId, string $requestHash): AdminActionLog
    {
        $connection = $subject->getConnection();
        if ($connection->transactionLevel() < 1 || ! $actor->isAdmin() || ! $subject instanceof Category || ! $subject->exists) {
            throw new LogicException('Administrative logs require an authorized originating transaction and known subject.');
        }
        if (! User::on($connection->getName())->whereKey($actor->id)->where('role', User::ROLE_ADMIN)->exists()) {
            throw new LogicException('The administrative actor must still be an administrator on the originating connection.');
        }
        if ($action !== AdminAction::CategoryCreated && $before === $after) {
            throw new LogicException('Unchanged administrative operations must not create audit events.');
        }
        foreach ([[$before, $action->fields(true)], [$after, $action->fields()]] as [$values, $fields]) {
            $keys = array_keys($values);
            sort($keys);
            sort($fields);
            if ($keys !== $fields) {
                throw new LogicException('Only whitelisted administrative before/after values may be logged.');
            }
        }
        $log = new AdminActionLog;
        $log->setConnection($connection->getName());
        $log->actor_id = $actor->id;
        $log->subject_type = $subject->getMorphClass();
        $log->subject_id = $subject->id;
        $log->action = $action;
        $log->before_values = $before;
        $log->after_values = $after;
        $log->occurred_at = CarbonImmutable::now('UTC');
        $log->action_id = $actionId;
        $log->request_hash = $requestHash;
        if (! $log->save()) {
            throw new RuntimeException('The administrative audit event could not be recorded.');
        }

        return $log;
    }
}
