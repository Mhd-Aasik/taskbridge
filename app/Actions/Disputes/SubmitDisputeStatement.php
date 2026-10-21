<?php

namespace App\Actions\Disputes;

use App\Enums\DisputeStatementKind;
use App\Models\DisputeStatement;
use App\Support\DisputeContext;
use App\Support\DisputeRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubmitDisputeStatement
{
    public function submit(int $caseId, array $input, string $submissionId): DisputeStatement
    {
        try {
            return $this->persist($caseId, $input, $submissionId);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            if (! str_contains($message, 'dispute_statements_submission_id_unique') && ! str_contains($message, 'UNIQUE constraint failed: dispute_statements.submission_id')) {
                throw $exception;
            }

            return $this->persist($caseId, $input, $submissionId, $exception);
        }
    }

    private function persist(int $caseId, array $input, string $submissionId, ?UniqueConstraintViolationException $conflict = null): DisputeStatement
    {
        return DB::transaction(function () use ($caseId, $input, $submissionId, $conflict): DisputeStatement {
            [$user, $project, $assignment, $dispute] = DisputeContext::forCase($caseId, lock: true);
            Gate::forUser($user)->authorize('submitStatement', [$dispute, $assignment, $project]);
            $token = DisputeRules::token($submissionId);
            $body = DisputeRules::statement($input);
            if ($existing = $dispute->statements()->where('submission_id', $token)->lockForUpdate()->first()) {
                if ($existing->submitted_by === $user->id && $existing->body === $body) {
                    return $existing;
                }
                throw ValidationException::withMessages(['statement' => 'This identifier already records a different statement. Submitted statements cannot be changed.']);
            }
            if (DisputeStatement::query()->where('submission_id', $token)->exists()) {
                throw ValidationException::withMessages(['statement' => 'This statement identifier has already been used. Start a new statement.']);
            }
            if ($conflict) {
                throw $conflict;
            }
            $active = $assignment->activeDispute()->lockForUpdate()->first();
            if ($active?->dispute_case_id !== $dispute->id || $dispute->resolution()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['statement' => 'This case is closed. Its discussion remains available, but new statements cannot be added.']);
            }
            $statement = new DisputeStatement;
            $statement->dispute()->associate($dispute);
            $statement->author()->associate($user);
            $statement->kind = $user->isAdmin() ? DisputeStatementKind::Clarification : DisputeStatementKind::Statement;
            $statement->body = $body;
            $statement->submitted_at = CarbonImmutable::now('UTC');
            $statement->submission_id = $token;
            if (! $statement->save()) {
                throw new RuntimeException('The statement could not be saved.');
            }

            return $statement;
        });
    }
}
