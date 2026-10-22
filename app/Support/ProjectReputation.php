<?php

namespace App\Support;

use App\Models\ProjectRating;
use Illuminate\Database\Eloquent\Builder;

class ProjectReputation
{
    /** Reuse the same released-only aggregation for discovery ordering and summaries. */
    public static function aggregateQuery(string $role): Builder
    {
        return ProjectRating::query()->released()->receivedAs($role)
            ->select('reviewee_id')->selectRaw('COUNT(*) as rating_count, AVG(rating) as rating_average')->groupBy('reviewee_id');
    }

    /** One aggregate query for a page of accounts; no cache can outlive the time-based release. */
    public static function forUsers(array $ids, string $role): array
    {
        $summaries = array_fill_keys($ids, ['count' => 0, 'average' => null, 'role' => $role]);
        if ($ids === []) {
            return $summaries;
        }
        $rows = self::aggregateQuery($role)->whereIn('reviewee_id', $ids)->get();
        foreach ($rows as $row) {
            $summaries[$row->reviewee_id] = ['count' => (int) $row->rating_count, 'average' => number_format((float) $row->rating_average, 1, '.', ''), 'role' => $role];
        }

        return $summaries;
    }
}
