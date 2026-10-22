<?php

namespace App\Models;

use App\Enums\ProjectActivityEvent;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectActivityFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProjectActivity extends Model
{
    /** @use HasFactory<ProjectActivityFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['event_key'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Project activity cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Project activity must be preserved.'));
    }

    protected function casts(): array
    {
        return [
            'project_id' => 'integer', 'project_assignment_id' => 'integer', 'actor_id' => 'integer',
            'event_type' => ProjectActivityEvent::class, 'metadata' => 'array', 'occurred_at' => 'immutable_datetime',
        ];
    }

    protected function occurredAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
