<?php

namespace App\Support;

use App\Models\Milestone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompletionRules
{
    public static function note(array $input, bool $required = false): ?string
    {
        $note = $input['completion_note'] ?? null;
        if (is_string($note)) {
            $note = Str::trim($note);
            $note = $note === '' ? null : $note;
        }

        return Validator::make(['completion_note' => $note, 'confirmed' => $input['confirmed'] ?? false], [
            'completion_note' => [$required ? 'required' : 'nullable', 'string', 'max:2000', 'not_regex:/\A[\s\p{Z}\p{C}]*\z/u'],
            'confirmed' => ['required', 'accepted'],
        ], ['completion_note.required' => 'Explain the outcome when a milestone has no tasks or deliverables.', 'confirmed.accepted' => 'Confirm completion before continuing.'])->validate()['completion_note'];
    }

    public static function ensureMutable(?Milestone $milestone, string $field = 'plan'): void
    {
        if ($milestone?->isCompleted()) {
            throw ValidationException::withMessages([$field => 'This milestone is completed. Its details, tasks, deliverables and task membership are locked.']);
        }
    }

    public static function assertRetry(Model $record, int $userId, ?string $note): void
    {
        if ($record->completed_by !== $userId || $record->completion_note !== $note) {
            throw ValidationException::withMessages(['completion' => 'Completion is already recorded. Its actor, time and note cannot be changed.']);
        }
    }
}
