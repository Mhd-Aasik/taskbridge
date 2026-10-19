<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = ['title', 'description', 'category_id', 'budget', 'deadline'];

    protected $attributes = ['status' => ProjectStatus::Draft->value];

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'category_id' => 'integer',
            'title' => 'string',
            'description' => 'string',
            // Fixed budget in LKR; keep the decimal value as a string.
            'budget' => 'decimal:2',
            'deadline' => 'immutable_date:Y-m-d',
            'status' => ProjectStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
