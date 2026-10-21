<?php

namespace App\Models;

use Database\Factories\ProjectReadCursorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProjectReadCursor extends Model
{
    /** @use HasFactory<ProjectReadCursorFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['project_assignment_id' => 'integer', 'user_id' => 'integer', 'last_read_message_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $cursor): void {
            if ($cursor->isDirty(['project_assignment_id', 'user_id']) || $cursor->last_read_message_id < $cursor->getOriginal('last_read_message_id')) {
                throw new LogicException('A read cursor cannot change its owner or assignment, or move backwards.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Conversation read history must be preserved.');
        });
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ProjectMessage, $this> */
    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(ProjectMessage::class, 'last_read_message_id');
    }
}
