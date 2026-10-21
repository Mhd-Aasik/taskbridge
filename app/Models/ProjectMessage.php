<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProjectMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProjectMessage extends Model
{
    /** @use HasFactory<ProjectMessageFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['submission_id'];

    protected function casts(): array
    {
        return ['project_assignment_id' => 'integer', 'sender_id' => 'integer', 'body' => 'string', 'sent_at' => 'immutable_datetime', 'submission_id' => 'string'];
    }

    protected function sentAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Sent project messages cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Project conversation history must be preserved.');
        });
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @param Builder<ProjectMessage> $query */
    public function scopeUnreadFor(Builder $query, User $user): void
    {
        $query->where('project_messages.sender_id', '!=', $user->id)
            ->whereRaw('project_messages.id > COALESCE((SELECT last_read_message_id FROM project_read_cursors WHERE project_read_cursors.project_assignment_id = project_messages.project_assignment_id AND project_read_cursors.user_id = ?), 0)', [$user->id]);
    }
}
