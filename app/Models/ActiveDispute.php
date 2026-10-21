<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ActiveDispute extends Model
{
    protected $guarded = ['*'];

    protected $primaryKey = 'project_assignment_id';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['project_assignment_id' => 'integer', 'dispute_case_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('An active dispute cannot be reassigned.');
        });
        static::deleting(function (self $active): void {
            if (! $active->dispute()->whereHas('resolution')->exists()) {
                throw new LogicException('An active dispute requires a recorded resolution before closure.');
            }
        });
    }

    /** @return BelongsTo<DisputeCase, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(DisputeCase::class, 'dispute_case_id');
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }
}
