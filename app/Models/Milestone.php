<?php

namespace App\Models;

use App\Models\Concerns\HasCompletionMetadata;
use Carbon\CarbonImmutable;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Milestone extends Model
{
    /** @use HasFactory<MilestoneFactory> */
    use HasCompletionMetadata, HasFactory;

    protected $fillable = ['title', 'description', 'due_date'];

    protected function casts(): array
    {
        return [
            'project_assignment_id' => 'integer',
            'created_by' => 'integer',
            'title' => 'string',
            'description' => 'string',
            'due_date' => 'immutable_date:Y-m-d',
            'position' => 'integer',
            'details_version' => 'integer',
            'completed_at' => 'immutable_datetime',
            'completed_by' => 'integer',
            'completion_note' => 'string',
        ];
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProjectTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    /** @return HasMany<Deliverable, $this> */
    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class)->orderBy('id');
    }

    public function isOverdue(): bool
    {
        return ! $this->isCompleted() && $this->due_date->toDateString() < CarbonImmutable::today('Asia/Colombo')->toDateString();
    }
}
