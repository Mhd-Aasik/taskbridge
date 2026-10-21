<?php

namespace App\Support;

use App\Models\DeliverableVersion;
use App\Models\ProjectAssignment;

class DeliverableReviewSummary
{
    /** @return array{total: int, approved: int, awaiting_review: int, changes_requested: int} */
    public static function forAssignment(ProjectAssignment $assignment): array
    {
        $counts = DeliverableVersion::query()
            ->whereHas('deliverable', fn ($query) => $query->where('project_assignment_id', $assignment->id))
            ->where('version_number', function ($query) {
                $query->selectRaw('MAX(latest.version_number)')->from('deliverable_versions as latest')
                    ->whereColumn('latest.deliverable_id', 'deliverable_versions.deliverable_id');
            })
            ->leftJoin('deliverable_reviews', 'deliverable_reviews.deliverable_version_id', '=', 'deliverable_versions.id')
            ->selectRaw('COUNT(*) as total,
                COALESCE(SUM(CASE WHEN decision = ? THEN 1 ELSE 0 END), 0) as approved,
                COALESCE(SUM(CASE WHEN deliverable_reviews.id IS NULL THEN 1 ELSE 0 END), 0) as awaiting_review,
                COALESCE(SUM(CASE WHEN decision = ? THEN 1 ELSE 0 END), 0) as changes_requested', ['approved', 'changes_requested'])
            ->first();

        return [
            'total' => (int) $counts->total,
            'approved' => (int) $counts->approved,
            'awaiting_review' => (int) $counts->awaiting_review,
            'changes_requested' => (int) $counts->changes_requested,
        ];
    }
}
