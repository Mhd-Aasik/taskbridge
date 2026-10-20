<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Services\ProjectCovers;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = ['title', 'description', 'category_id', 'budget', 'deadline'];

    protected $hidden = ['cover_path'];

    protected $attributes = ['status' => ProjectStatus::Draft->value];

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'category_id' => 'integer',
            'title' => 'string',
            'description' => 'string',
            'cover_path' => 'string',
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

    /** @return HasMany<Proposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /** @return HasOne<ProjectAssignment, $this> */
    public function assignment(): HasOne
    {
        return $this->hasOne(ProjectAssignment::class);
    }

    /** @param Builder<Project> $query */
    public function scopeDiscoverable(Builder $query): void
    {
        $now = CarbonImmutable::now('UTC');
        $query->where('status', ProjectStatus::Open->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true))
            ->whereDate('deadline', '>=', $now->setTimezone('Asia/Colombo')->toDateString());
    }

    public function coverUrl(): ?string
    {
        if (! ProjectCovers::ownsPath($this, $this->cover_path)) {
            return null;
        }

        return route('projects.cover', ['project' => $this->getKey(), 'v' => substr(hash('sha256', $this->cover_path), 0, 16)]);
    }

    public function formattedBudget(): ?string
    {
        $raw = (string) $this->getRawOriginal('budget');
        if (! preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $raw)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        return 'LKR '.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.str_pad($fraction, 2, '0');
    }

    public function calendarDeadline(): ?CarbonImmutable
    {
        // SQLite can retain Eloquent's midnight suffix in a DATE column.
        $raw = (string) $this->getRawOriginal('deadline');
        if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?: 00:00:00)?\z/', $raw, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10), 'Asia/Colombo');
    }
}
