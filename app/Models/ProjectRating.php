<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectRatingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

class ProjectRating extends Model
{
    /** @use HasFactory<ProjectRatingFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['submission_id'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Submitted project feedback cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Project feedback history must be preserved.');
        });
    }

    protected function casts(): array
    {
        return ['project_assignment_id' => 'integer', 'reviewer_id' => 'integer', 'reviewee_id' => 'integer', 'rating' => 'integer', 'feedback' => 'string', 'submitted_at' => 'immutable_datetime'];
    }

    protected function submittedAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** Released-only query; never load hidden rows and then hide them in a template. */
    public function scopeReleased(Builder $query, ?CarbonImmutable $at = null): void
    {
        $now = ($at ?? CarbonImmutable::now('UTC'))->utc();
        $query->whereExists(function (QueryBuilder $eligible) use ($now): void {
            $eligible->selectRaw('1')->from('project_assignments as feedback_assignment')
                ->join('projects as feedback_project', 'feedback_project.id', '=', 'feedback_assignment.project_id')
                ->whereColumn('feedback_assignment.id', 'project_ratings.project_assignment_id')
                ->whereColumn('feedback_project.client_id', '<>', 'feedback_assignment.freelancer_id')
                ->where('feedback_project.status', ProjectStatus::Completed->value)
                ->whereColumn('feedback_project.completed_by', 'feedback_project.client_id')
                ->whereNotNull('feedback_project.completed_at')->where('feedback_project.completed_at', '<=', $now)
                ->where(function (QueryBuilder $pair): void {
                    $pair->where(fn (QueryBuilder $client) => $client->whereColumn('project_ratings.reviewer_id', 'feedback_project.client_id')->whereColumn('project_ratings.reviewee_id', 'feedback_assignment.freelancer_id'))
                        ->orWhere(fn (QueryBuilder $freelancer) => $freelancer->whereColumn('project_ratings.reviewer_id', 'feedback_assignment.freelancer_id')->whereColumn('project_ratings.reviewee_id', 'feedback_project.client_id'));
                })
                ->where(function (QueryBuilder $release) use ($now): void {
                    $release->where('feedback_project.completed_at', '<=', $now->subDays(14))
                        ->orWhereExists(fn (QueryBuilder $other) => $other->selectRaw('1')->from('project_ratings as counter_feedback')
                            ->whereColumn('counter_feedback.project_assignment_id', 'project_ratings.project_assignment_id')
                            ->whereColumn('counter_feedback.reviewer_id', 'project_ratings.reviewee_id')
                            ->whereColumn('counter_feedback.reviewee_id', 'project_ratings.reviewer_id'));
                });
        });
    }

    public function scopeReceivedAs(Builder $query, string $role): void
    {
        if (! in_array($role, [User::ROLE_CLIENT, User::ROLE_FREELANCER], true)) {
            throw new LogicException('Project reputation must identify the participant role.');
        }
        $query->whereExists(fn (QueryBuilder $participant) => $participant->selectRaw('1')->from('project_assignments as reputation_assignment')
            ->join('projects as reputation_project', 'reputation_project.id', '=', 'reputation_assignment.project_id')
            ->whereColumn('reputation_assignment.id', 'project_ratings.project_assignment_id')
            ->whereColumn('project_ratings.reviewee_id', $role === User::ROLE_CLIENT ? 'reputation_project.client_id' : 'reputation_assignment.freelancer_id'));
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewee_id');
    }
}
