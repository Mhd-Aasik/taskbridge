<?php

namespace App\Actions\Deliverables;

use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Services\DeliverableFiles;
use App\Support\ProjectPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SubmitDeliverable
{
    public function submit(int $projectId, ?int $deliverableId, array $input, mixed $file, string $submissionId): DeliverableVersion
    {
        $newPath = null;
        $files = app(DeliverableFiles::class);
        try {
            $version = DB::transaction(function () use ($projectId, $deliverableId, $input, $file, $submissionId, $files, &$newPath): DeliverableVersion {
                [$user, $project, $assignment] = ProjectPlanning::context($projectId, lock: true);
                abort_unless($user->isFreelancer() && $assignment->freelancer_id === $user->id, 403);
                Validator::make(['submission_id' => $submissionId], ['submission_id' => ['required', 'uuid']])->validate();

                // A retry reads the committed result even if the file expired or the project finished.
                if ($existing = $this->existing($assignment, $user, $deliverableId, $submissionId)) {
                    return $existing;
                }
                if ($project->status !== ProjectStatus::InProgress) {
                    throw ValidationException::withMessages(['deliverables' => 'New uploads are unavailable because this project is no longer in progress. Existing versions remain available.']);
                }

                $deliverable = $deliverableId === null ? null : $assignment->deliverables()->findOrFail($deliverableId);
                $milestoneId = $deliverable?->milestone_id;
                $details = [];
                if (! $deliverable) {
                    $details = $this->details($input);
                    $milestoneId = $details['milestone_id'];
                    unset($details['milestone_id']);
                }
                // Account -> project -> assignment -> milestone -> deliverable -> versions.
                $milestone = $milestoneId === null ? null : $assignment->milestones()->whereKey($milestoneId)->lockForUpdate()->first();
                if ($milestoneId !== null && ! $milestone) {
                    throw ValidationException::withMessages(['milestone_id' => 'Choose a milestone from this project, or leave the deliverable ungrouped.']);
                }
                if ($deliverable) {
                    $deliverable = $assignment->deliverables()->lockForUpdate()->findOrFail($deliverableId);
                    if ($deliverable->isApproved()) {
                        throw ValidationException::withMessages(['deliverables' => 'This deliverable has been approved and cannot receive more versions. You can still upload work to other deliverables.']);
                    }
                    Gate::forUser($user)->authorize('uploadVersion', [$deliverable, $assignment, $project]);
                } else {
                    Gate::forUser($user)->authorize('create', [Deliverable::class, $assignment, $project]);
                }
                $note = $input['submission_note'] ?? null;
                if (is_string($note)) {
                    $note = Str::trim($note);
                    $note = $note === '' ? null : $note;
                }
                $note = Validator::make(['submission_note' => $note], ['submission_note' => ['nullable', 'string', 'max:5000']])->validate()['submission_note'];
                $prepared = $files->prepare($file);

                if (! $deliverable) {
                    $deliverable = new Deliverable($details);
                    $deliverable->assignment()->associate($assignment);
                    $deliverable->milestone()->associate($milestone);
                    $deliverable->creator()->associate($user);
                    if (! $deliverable->save()) {
                        throw new RuntimeException('The deliverable could not be created.');
                    }
                }
                $number = ((int) $deliverable->versions()->reorder()->orderByDesc('version_number')->lockForUpdate()->value('version_number')) + 1;
                $candidate = 'deliverables/'.$deliverable->id.'/'.Str::uuid().'.'.$prepared['extension'];
                $disk = Storage::disk('deliverables');
                // The shared assignment lock also serializes this defensive collision check.
                if ($disk->exists($candidate) || DeliverableVersion::query()->where('storage_path', $candidate)->exists()) {
                    throw new RuntimeException('A unique upload filename could not be allocated. Please retry.');
                }
                $newPath = $candidate;
                DB::afterRollBack(fn () => $files->deleteUnreferencedFile($newPath));
                if (! $disk->put($newPath, $prepared['contents'])) {
                    throw new RuntimeException('The deliverable file could not be stored.');
                }
                $version = new DeliverableVersion;
                $version->deliverable()->associate($deliverable);
                $version->uploader()->associate($user);
                $version->version_number = $number;
                $version->storage_path = $newPath;
                $version->original_filename = $prepared['original_filename'];
                $version->mime_type = $prepared['mime_type'];
                $version->byte_size = $prepared['byte_size'];
                $version->sha256 = $prepared['sha256'];
                $version->submission_note = $note;
                $version->submitted_at = CarbonImmutable::now('UTC');
                $version->submission_id = $submissionId;
                if (! $version->save()) {
                    throw new RuntimeException('The deliverable version could not be saved.');
                }

                return $version;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $files->deleteUnreferencedFile($newPath);
            $message = (string) ($exception->errorInfo[2] ?? '');
            $tokenConflict = str_contains($message, 'deliverable_versions_submission_id_unique')
                || str_contains($message, 'UNIQUE constraint failed: deliverable_versions.submission_id');
            if (! $tokenConflict) {
                throw $exception;
            }
            // Only the known submission-id constraint is recoverable as an idempotent retry.
            $version = DB::transaction(function () use ($projectId, $deliverableId, $submissionId): DeliverableVersion {
                [$user, , $assignment] = ProjectPlanning::context($projectId, lock: true);
                abort_unless($user->isFreelancer() && $assignment->freelancer_id === $user->id, 403);

                return $this->existing($assignment, $user, $deliverableId, $submissionId)
                    ?? throw ValidationException::withMessages(['deliverables' => 'This submission identifier has already been used. Reload the upload form before trying again.']);
            });
        } catch (Throwable $exception) {
            // The helper checks durable references, including when a commit callback failed.
            $files->deleteUnreferencedFile($newPath);
            throw $exception;
        }
        if ($newPath !== null) {
            // Attach to an enclosing transaction after our own savepoint commits as well.
            DB::afterRollBack(fn () => $files->deleteUnreferencedFile($newPath));
        }

        return $version;
    }

    private function existing(ProjectAssignment $assignment, User $user, ?int $deliverableId, string $submissionId): ?DeliverableVersion
    {
        $existing = DeliverableVersion::query()->where('submission_id', $submissionId)->where('uploaded_by', $user->id)
            ->whereHas('deliverable', fn ($query) => $query->where('project_assignment_id', $assignment->id))->lockForUpdate()->first();
        if ($existing && (($deliverableId !== null && $existing->deliverable_id !== $deliverableId)
            || ($deliverableId === null && $existing->version_number !== 1))) {
            throw ValidationException::withMessages(['deliverables' => 'This submission belongs to a different upload. Reload the upload form.']);
        }

        return $existing;
    }

    private function details(array $input): array
    {
        $details = array_intersect_key($input, array_flip(['title', 'description', 'milestone_id']));
        foreach (['title', 'description'] as $field) {
            if (is_string($details[$field] ?? null)) {
                $details[$field] = Str::trim($details[$field]);
            }
        }
        $details['description'] = ($details['description'] ?? '') === '' ? null : $details['description'];
        $details['milestone_id'] = ($details['milestone_id'] ?? '') === '' ? null : $details['milestone_id'];

        return Validator::make($details, [
            'title' => ['required', 'string', 'max:150', 'not_regex:/\A[\s\p{Z}]*\z/u'],
            'description' => ['nullable', 'string', 'max:5000'],
            'milestone_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
    }
}
