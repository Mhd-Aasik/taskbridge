<?php

namespace App\Support;

use App\Enums\ProjectTaskStatus;
use App\Models\ProjectAssignment;

class TaskProgress
{
    /** @return array{total: int, completed: int, percentage: int} */
    public static function forAssignment(ProjectAssignment $assignment): array
    {
        $counts = $assignment->tasks()->reorder()->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as completed', [ProjectTaskStatus::Done->value])->first();

        return self::summarize((int) $counts->total, (int) $counts->completed);
    }

    /** @return array{total: int, completed: int, percentage: int} */
    public static function summarize(int $total, int $completed): array
    {
        return ['total' => $total, 'completed' => $completed, 'percentage' => $total === 0 ? 0 : intdiv($completed * 100, $total)];
    }
}
