<?php

namespace App\Support;

use App\Models\ProjectAssignment;
use App\Models\User;

class ConversationHistory
{
    public const BATCH = 30;

    /** Start at the oldest unread incoming message so a long unread backlog is not skipped. */
    public static function initial(ProjectAssignment $assignment, User $user): array
    {
        $first = $assignment->messages()->unreadFor($user)->value('id');

        return $first === null ? self::latest($assignment) : $assignment->messages()->where('id', '>=', $first)->limit(self::BATCH)->pluck('id')->all();
    }

    public static function latest(ProjectAssignment $assignment): array
    {
        return $assignment->messages()->reorder()->orderByDesc('id')->limit(self::BATCH)->pluck('id')->reverse()->values()->all();
    }

    public static function older(ProjectAssignment $assignment, array $ids): array
    {
        if ($ids === []) {
            return self::latest($assignment);
        }
        $older = $assignment->messages()->where('id', '<', min($ids))->reorder()->orderByDesc('id')->limit(self::BATCH)->pluck('id')->reverse()->all();

        return array_slice(array_values(array_unique(array_merge($older, $ids))), 0, MessagePresentation::WINDOW);
    }

    public static function newer(ProjectAssignment $assignment, array $ids): array
    {
        $newer = $assignment->messages()->where('id', '>', $ids === [] ? 0 : max($ids))->limit(self::BATCH)->pluck('id')->all();

        return array_slice(array_values(array_unique(array_merge($ids, $newer))), -MessagePresentation::WINDOW);
    }
}
