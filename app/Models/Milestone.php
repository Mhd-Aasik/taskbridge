<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Milestone extends Model
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory;

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

    public function isOverdue(): bool
    {
        return $this->due_date->toDateString() < CarbonImmutable::today('Asia/Colombo')->toDateString();
    }
}
