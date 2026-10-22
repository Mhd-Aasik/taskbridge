<?php

namespace App\Actions\Accounts;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\User;
use App\Services\AccountSessions;
use App\Services\AdminActionLogs;
use App\Support\AccountAccess;
use App\Support\AccountManagement;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChangeAccountStatus
{
    public function change(int $targetId, bool $suspend, array $input, string $revision, string $actionId, bool $confirmed): array
    {
        try {
            return $this->persist($targetId, $suspend, $input, $revision, $actionId, $confirmed);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            if (! str_contains($message, 'admin_action_logs_action_id_unique') && ! str_contains($message, 'UNIQUE constraint failed: admin_action_logs.action_id')) {
                throw $exception;
            }

            return $this->persist($targetId, $suspend, $input, $revision, $actionId, $confirmed, $exception);
        }
    }

    private function persist(int $targetId, bool $suspend, array $input, string $revision, string $actionId, bool $confirmed, ?UniqueConstraintViolationException $conflict = null): array
    {
        return DB::transaction(function () use ($targetId, $suspend, $input, $revision, $actionId, $confirmed, $conflict): array {
            abort_unless(Auth::check(), 403);
            // Sorted account locks agree with proposal acceptance and feedback actions.
            $accounts = User::query()->select(AccountManagement::STATUS_FIELDS)->whereIn('id', [Auth::id(), $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $accounts->get(Auth::id());
            abort_unless($actor, 403);
            AccountAccess::ensure($actor);
            Gate::forUser($actor)->authorize('viewAdminAny', User::class);
            $account = $accounts->get($targetId);
            abort_unless($account, 404);
            Gate::forUser($actor)->authorize('changeStatus', $account);
            $reason = $input['reason'] ?? null;
            if (is_string($reason)) {
                $reason = Str::trim($reason);
            }
            $validated = Validator::make(['reason' => $reason, 'revision' => $revision, 'action_id' => $actionId, 'confirmed' => $confirmed], [
                'reason' => ['bail', 'required', 'string', 'max:1000', 'regex:/[\p{L}\p{N}]/u'],
                'revision' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'action_id' => ['required', 'uuid'], 'confirmed' => ['accepted'],
            ], ['reason.regex' => 'Give a meaningful reason containing letters or numbers.'])->validate();
            $action = $suspend ? AdminAction::AccountSuspended : AdminAction::AccountReactivated;
            $hash = hash('sha256', json_encode([$targetId, $action->value, $validated['reason'], $revision], JSON_THROW_ON_ERROR));
            if ($receipt = AdminActionLog::query()->where('action_id', $actionId)->first()) {
                if ($receipt->actor_id !== $actor->id || $receipt->subject_type !== $account->getMorphClass() || $receipt->subject_id !== $account->id || $receipt->action !== $action || ! hash_equals($receipt->request_hash, $hash)) {
                    throw ValidationException::withMessages(['account' => 'This action identifier records a different request. Reload before continuing.']);
                }

                return ['account' => $account, 'log' => $receipt, 'changed' => false, 'replayed' => true];
            }
            if ($conflict) {
                throw $conflict;
            }
            if (! hash_equals(AccountManagement::version($account), $revision)) {
                throw ValidationException::withMessages(['account' => 'This account changed after confirmation was prepared. Reload its current status before continuing.']);
            }
            if ($account->isActive() !== $suspend) {
                return ['account' => $account, 'log' => null, 'changed' => false, 'replayed' => false];
            }
            $before = AccountManagement::snapshot($account);
            $account->suspended_at = $suspend ? CarbonImmutable::now('UTC') : null;
            $account->suspended_by = $suspend ? $actor->id : null;
            $account->suspension_reason = $suspend ? $validated['reason'] : null;
            $account->access_version++;
            $account->remember_token = Str::random(60);
            if (! $account->save()) {
                throw new RuntimeException('The account status could not be saved.');
            }
            $after = AccountManagement::snapshot($account) + ['reason' => $validated['reason']];
            $log = app(AdminActionLogs::class)->record($actor, $action, $account, $before, $after, $actionId, $hash);
            app(AccountSessions::class)->revoke($account);

            return ['account' => $account, 'log' => $log, 'changed' => true, 'replayed' => false];
        }, 3);
    }
}
