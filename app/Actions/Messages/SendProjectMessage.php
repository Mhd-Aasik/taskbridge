<?php

namespace App\Actions\Messages;

use App\Enums\NotificationEvent;
use App\Models\ProjectMessage;
use App\Services\ProjectNotifications;
use App\Support\ProjectMessageRules;
use App\Support\ProjectMessaging;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SendProjectMessage
{
    public const LIMIT = 20;

    public static function rateKey(int $userId): string
    {
        return 'project-messages:user:'.$userId;
    }

    public function send(int $projectId, array $input, string $submissionId): ProjectMessage
    {
        try {
            return $this->persist($projectId, $input, $submissionId);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            if (! str_contains($message, 'project_messages_submission_id_unique') && ! str_contains($message, 'UNIQUE constraint failed: project_messages.submission_id')) {
                throw $exception;
            }

            return $this->persist($projectId, $input, $submissionId, $exception);
        }
    }

    private function persist(int $projectId, array $input, string $submissionId, ?UniqueConstraintViolationException $conflict = null): ProjectMessage
    {
        return DB::transaction(function () use ($projectId, $input, $submissionId, $conflict): ProjectMessage {
            [$user, $project, $assignment] = ProjectMessaging::context($projectId, lock: true);
            Gate::forUser($user)->authorize('send', [ProjectMessage::class, $assignment, $project]);
            $body = ProjectMessageRules::body($input);
            $token = ProjectMessageRules::token($submissionId);
            if ($existing = $assignment->messages()->where('submission_id', $token)->lockForUpdate()->first()) {
                if ($existing->sender_id === $user->id && $existing->body === $body) {
                    return $existing;
                }
                throw ValidationException::withMessages(['message_conflict' => 'This request already records a different message. Review the conversation before starting a new message.']);
            }
            if (ProjectMessage::query()->where('submission_id', $token)->exists()) {
                throw ValidationException::withMessages(['message_conflict' => 'This sending identifier has already been used. Start a new message.']);
            }
            if ($conflict) {
                throw $conflict;
            }
            $key = self::rateKey($user->id);
            if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
                throw ValidationException::withMessages(['body' => 'Please wait '.max(1, RateLimiter::availableIn($key)).' seconds before sending again. You can send up to 20 new messages per minute across your projects. Your text has been kept.']);
            }
            // The account lock serializes this limit across projects. Exact retries bypass it.
            RateLimiter::hit($key, 60);
            $message = new ProjectMessage;
            $message->assignment()->associate($assignment);
            $message->sender()->associate($user);
            $message->body = $body;
            $message->sent_at = CarbonImmutable::now('UTC');
            $message->submission_id = $token;
            if (! $message->save()) {
                throw new RuntimeException('The message could not be saved.');
            }

            app(ProjectNotifications::class)->record(NotificationEvent::ProjectMessageSent, $message);

            return $message;
        }, 3);
    }
}
