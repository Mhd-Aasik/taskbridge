<?php

namespace App\Models;

use App\Enums\ProjectTaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectTaskFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTask extends Model
{
    /** @use HasFactory<ProjectTaskFactory> */
    use HasFactory;

    // Milestone membership is also assigned explicitly after scoped resolution.
    protected $fillable = ['title', 'description', 'due_date'];

    protected $attributes = ['status' => ProjectTaskStatus::Todo->value, 'details_version' => 1, 'status_version' => 1];

    protected function casts(): array
    {
        return [
            'project_assignment_id' => 'integer',
            'milestone_id' => 'integer',
            'created_by' => 'integer',
            'title' => 'string',
            'description' => 'string',
            'due_date' => 'immutable_date:Y-m-d',
            'status' => ProjectTaskStatus::class,
            'completed_at' => 'immutable_datetime',
            'details_version' => 'integer',
            'status_version' => 'integer',
        ];
    }

    protected function completedAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()),
        );
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }

    /** @return BelongsTo<Milestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOverdue(): bool
    {
        return $this->status !== ProjectTaskStatus::Done
            && $this->due_date->toDateString() < CarbonImmutable::today('Asia/Colombo')->toDateString();
    }
}
