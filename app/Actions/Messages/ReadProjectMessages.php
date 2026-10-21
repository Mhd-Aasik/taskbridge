<?php

namespace App\Actions\Messages;

use App\Models\ProjectMessage;
use App\Models\ProjectReadCursor;
use App\Support\MessagePresentation;
use App\Support\ProjectMessaging;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class ReadProjectMessages
{
    public function mark(int $projectId, string $presentation): ?ProjectReadCursor
    {
        try {
            return $this->persist($projectId, $presentation);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            if (! str_contains($message, 'project_read_cursors_project_assignment_id_user_id_unique') && ! str_contains($message, 'UNIQUE constraint failed: project_read_cursors.project_assignment_id, project_read_cursors.user_id')) {
                throw $exception;
            }

            return $this->persist($projectId, $presentation, $exception);
        }
    }

    private function persist(int $projectId, string $presentation, ?UniqueConstraintViolationException $conflict = null): ?ProjectReadCursor
    {
        return DB::transaction(function () use ($projectId, $presentation, $conflict): ?ProjectReadCursor {
            [$user, $project, $assignment] = ProjectMessaging::context($projectId, lock: true);
            Gate::forUser($user)->authorize('markRead', [ProjectMessage::class, $assignment, $project]);
            $ids = MessagePresentation::verify($presentation, $user, $assignment);
            $cursor = $assignment->readCursors()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($conflict && ! $cursor) {
                throw $conflict;
            }
            $through = max($ids);
            if ($cursor && $through <= $cursor->last_read_message_id) {
                return $cursor;
            }
            // Do not skip an undisplayed incoming backlog when a window jumps forward.
            if ($assignment->messages()->unreadFor($user)->where('id', '<', min($ids))->exists()) {
                return $cursor;
            }
            if (! $cursor) {
                $cursor = new ProjectReadCursor;
                $cursor->assignment()->associate($assignment);
                $cursor->user()->associate($user);
            }
            $cursor->last_read_message_id = $through;
            if (! $cursor->save()) {
                throw new RuntimeException('The read cursor could not be saved.');
            }

            return $cursor;
        }, 3);
    }
}
