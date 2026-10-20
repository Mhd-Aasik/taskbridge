<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Carbon\CarbonImmutable;
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
