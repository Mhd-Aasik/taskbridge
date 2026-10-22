<?php

namespace App\Actions\Proposals;

use App\Enums\NotificationEvent;
use App\Enums\ProposalStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ProjectNotifications;
use App\Support\AccountAccess;
use App\Support\ProposalSubmissionRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubmitProposal
{
    /** @return array{proposal: Proposal, created: bool} */
    public function submit(int $projectId, array $input): array
    {
        abort_unless(Auth::check(), 403);

        return DB::transaction(function () use ($projectId, $input): array {
            $clientId = Project::query()->whereKey($projectId)->value('client_id');
            $accounts = User::query()->whereIn('id', [Auth::id(), $clientId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user = $accounts->get(Auth::id());
            abort_unless($user, 403);
            AccountAccess::ensure($user);
            $project = Project::query()->lockForUpdate()->findOrFail($projectId);
            Gate::forUser($user)->authorize('create', [Proposal::class, $project]);

            if ($project->client_id !== (int) $clientId || ! $accounts->get($project->client_id)?->isActive()) {
                throw ValidationException::withMessages(['project' => 'This project is no longer available for proposals.']);
            }

            Category::query()->whereKey($project->category_id)->lockForUpdate()->first();
            if ($existing = $this->existing($user, $project)) {
                return ['proposal' => $existing, 'created' => false];
            }
            if ($reason = ProposalSubmissionRules::unavailableReason($project)) {
                throw ValidationException::withMessages(['project' => $reason]);
            }
            // Keep the lock order above, and re-read persisted activity at the final eligibility check.
            $category = Category::query()->whereKey($project->category_id)->lockForUpdate()->first();
            if (! $category?->is_active) {
                throw ValidationException::withMessages(['project' => 'This project category is no longer active. Proposals are unavailable.']);
            }
            $details = ProposalSubmissionRules::validatedDetails($project, $input);

            try {
                // A savepoint keeps a relevant duplicate conflict recoverable on both supported databases.
                $proposal = DB::transaction(function () use ($user, $project, $details): Proposal {
                    $proposal = new Proposal($details);
                    $proposal->project()->associate($project);
                    $proposal->freelancer()->associate($user);
                    $proposal->status = ProposalStatus::Submitted;
                    $proposal->submitted_at = CarbonImmutable::now('UTC');
                    if (! $proposal->save()) {
                        throw new RuntimeException('The proposal could not be submitted.');
                    }

                    return $proposal;
                });
            } catch (UniqueConstraintViolationException $exception) {
                $message = (string) ($exception->errorInfo[2] ?? '');
                $pairConflict = preg_match('/\bproposals_project_id_freelancer_id_unique\b/', $message)
                    || str_contains($message, 'UNIQUE constraint failed: proposals.project_id, proposals.freelancer_id');
                if (! $pairConflict || ! ($existing = $this->existing($user, $project))) {
                    throw $exception;
                }

                return ['proposal' => $existing, 'created' => false];
            }

            app(ProjectNotifications::class)->record(NotificationEvent::ProposalSubmitted, $proposal);

            return ['proposal' => $proposal, 'created' => true];
        }, 3);
    }

    private function existing(User $user, Project $project): ?Proposal
    {
        return $user->submittedProposals()->where('project_id', $project->id)->lockForUpdate()->first();
    }
}
