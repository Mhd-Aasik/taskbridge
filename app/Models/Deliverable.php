<?php

namespace App\Models;

use Database\Factories\DeliverableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Deliverable extends Model
{
    /** @use HasFactory<DeliverableFactory> */
    use HasFactory;

    // Assignment, creator, and optional milestone are resolved by trusted code.
    protected $fillable = ['title', 'description'];

    protected function casts(): array
    {
        return [
            'project_assignment_id' => 'integer',
            'milestone_id' => 'integer',
            'created_by' => 'integer',
            'title' => 'string',
            'description' => 'string',
        ];
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

    /** @return HasMany<DeliverableVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DeliverableVersion::class)->orderByDesc('version_number')->orderByDesc('id');
    }

    /** @return HasOne<DeliverableVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DeliverableVersion::class)->ofMany('version_number', 'max');
    }
}
