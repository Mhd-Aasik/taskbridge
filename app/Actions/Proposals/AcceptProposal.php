<?php

namespace App\Actions\Proposals;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use App\Rules\LkrBudget;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AcceptProposal
{
    /** @return array{assignment: ProjectAssignment, created: bool} */
    public function accept(int $projectId, int $proposalId, bool $confirmed): array
    {
        abort_unless(Auth::check(), 403);

        return DB::transaction(function () use ($projectId, $proposalId, $confirmed): array {
            // Read only to identify the accounts to lock. Re-resolve the offer under locks below.
            $candidate = Proposal::query()->where('project_id', $projectId)->findOrFail($proposalId);
            // All account locks precede project locks, as in submission/publication/deletion.
            // Lock accounts in ID order; never lock the freelancer after locking the project.
            $accounts = User::query()->whereIn('id', [Auth::id(), $candidate->freelancer_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user = $accounts->get(Auth::id());
            abort_unless($user, 403);
            $project = Project::query()->lockForUpdate()->findOrFail($projectId);
            Gate::forUser($user)->authorize('view', $project);
            $category = Category::query()->whereKey($project->category_id)->lockForUpdate()->first();
            $proposal = $project->proposals()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('accept', [$proposal, $project]);

            Validator::make(['confirmed' => $confirmed], ['confirmed' => ['accepted']], [
                'confirmed.accepted' => 'Confirm the freelancer and agreed terms before accepting this proposal.',
            ])->validate();

            if ($existing = $project->assignment()->lockForUpdate()->first()) {
                return $this->existingSelection($existing, $proposal);
            }

            $now = CarbonImmutable::now('UTC');
            if ($project->status !== ProjectStatus::Open || ! $project->published_at || $project->published_at->greaterThan($now) || ! $category?->is_active) {
                throw ValidationException::withMessages(['project' => 'Acceptance requires an open, published project in an active category.']);
            }
            if ($proposal->status !== ProposalStatus::Submitted) {
                throw ValidationException::withMessages(['proposal' => 'Only a submitted proposal can be accepted. Refresh the proposal list to review its current status.']);
            }
            $freelancer = $accounts->get($proposal->freelancer_id);
            if ($proposal->freelancer_id !== $candidate->freelancer_id || ! $freelancer?->isFreelancer() || $freelancer->id === $user->id) {
                throw ValidationException::withMessages(['proposal' => 'The selected offer must belong to a different account with the Freelancer role.']);
            }

            $deadline = $project->calendarDeadline();
            if (! $deadline) {
                throw ValidationException::withMessages(['project' => 'The saved project deadline is invalid. This project cannot be assigned.']);
            }
            $rawDeadline = (string) $proposal->getRawOriginal('proposed_deadline');
            // SQLite may retain the midnight suffix in a DATE column. Do not normalize invalid dates.
            $offerDate = preg_match('/\A\d{4}-\d{2}-\d{2}(?: 00:00:00)?\z/', $rawDeadline) ? substr($rawDeadline, 0, 10) : $rawDeadline;
            $terms = Validator::make([
                // Validate stored precision before the decimal cast can round it.
                'proposed_budget' => (string) $proposal->getRawOriginal('proposed_budget'),
                'proposed_deadline' => $offerDate,
            ], [
                'proposed_budget' => ['bail', 'required', 'string', 'max:13', new LkrBudget],
                'proposed_deadline' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after:'.$now->setTimezone('Asia/Colombo')->toDateString(), 'before_or_equal:'.$deadline->toDateString()],
            ], [
                'proposed_deadline.after' => 'The saved offer has expired. Completion must still be tomorrow or later in Sri Lanka (Asia/Colombo).',
                'proposed_deadline.before_or_equal' => 'The offered completion date must be no later than the project deadline.',
            ])->validate();

            try {
                $assignment = DB::transaction(function () use ($project, $proposal, $terms, $now): ProjectAssignment {
                    $assignment = new ProjectAssignment;
                    $assignment->project()->associate($project);
                    $assignment->proposal()->associate($proposal);
                    $assignment->freelancer_id = $proposal->freelancer_id;
                    $assignment->agreed_budget = $terms['proposed_budget'];
                    $assignment->agreed_deadline = $terms['proposed_deadline'];
                    $assignment->assigned_at = $now;
                    if (! $assignment->save()) {
                        throw new RuntimeException('The assignment could not be saved.');
                    }

                    return $assignment;
                });
            } catch (UniqueConstraintViolationException $exception) {
                $message = (string) ($exception->errorInfo[2] ?? '');
                $assignmentConflict = preg_match('/\bproject_assignments_(project_id|proposal_id)_unique\b/', $message)
                    || preg_match('/UNIQUE constraint failed: project_assignments\.(project_id|proposal_id)\b/', $message);
                if (! $assignmentConflict) {
                    throw $exception;
                }
                if ($existing = $project->assignment()->lockForUpdate()->first()) {
                    return $this->existingSelection($existing, $proposal);
                }
                if (ProjectAssignment::query()->where('proposal_id', $proposal->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['proposal' => 'This proposal is already assigned and cannot be selected again.']);
                }
                throw $exception;
            }

            $proposal->status = ProposalStatus::Accepted;
            if (! $proposal->save()) {
                throw new RuntimeException('The accepted proposal could not be saved.');
            }
            $project->proposals()->whereKeyNot($proposal->id)->where('status', ProposalStatus::Submitted->value)
                ->update(['status' => ProposalStatus::Rejected->value, 'updated_at' => $now]);
            $project->status = ProjectStatus::InProgress;
            if (! $project->save()) {
                throw new RuntimeException('The assigned project could not be saved.');
            }

            return ['assignment' => $assignment, 'created' => true];
        }, 3);
    }

    private function existingSelection(ProjectAssignment $assignment, Proposal $proposal): array
    {
        if ($assignment->proposal_id !== $proposal->id || $assignment->freelancer_id !== $proposal->freelancer_id) {
            throw ValidationException::withMessages(['proposal' => 'This project already has an assigned freelancer. A different proposal cannot be accepted.']);
        }

        return ['assignment' => $assignment, 'created' => false];
    }
}
