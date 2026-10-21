<?php

namespace App\Support;

use App\Models\ProjectRating;

class ProjectReputation
{
    /** One aggregate query for a page of accounts; no cache can outlive the time-based release. */
    public static function forUsers(array $ids, string $role): array
    {
        $summaries = array_fill_keys($ids, ['count' => 0, 'average' => null, 'role' => $role]);
        if ($ids === []) {
            return $summaries;
        }
        $rows = ProjectRating::query()->released()->receivedAs($role)->whereIn('reviewee_id', $ids)
            ->select('reviewee_id')->selectRaw('COUNT(*) as rating_count, AVG(rating) as rating_average')->groupBy('reviewee_id')->get();
        foreach ($rows as $row) {
            $summaries[$row->reviewee_id] = ['count' => (int) $row->rating_count, 'average' => number_format((float) $row->rating_average, 1, '.', ''), 'role' => $role];
        }

        return $summaries;
    }
}
