<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Project;
use App\Rules\LkrBudget;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class ProjectRules
{
    public static function earliestDeadline(): string
    {
        return CarbonImmutable::today('Asia/Colombo')->addDay()->toDateString();
    }

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:10000'],
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')->where('is_active', true)],
            'budget' => ['bail', 'required', 'string', new LkrBudget],
            'deadline' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after:'.CarbonImmutable::today('Asia/Colombo')->toDateString()],
        ];
    }

    public static function messages(): array
    {
        return [
            'category_id.exists' => 'Choose an active project category.',
            'deadline.after' => 'Choose a deadline tomorrow or later in Sri Lanka (Asia/Colombo).',
        ];
    }

    public static function savedDetails(Project $project): array
    {
        return [
            'title' => $project->title,
            'description' => $project->description,
            'category_id' => $project->category_id,
            // Validate the stored precision before Eloquent's decimal cast can round it.
            'budget' => (string) $project->getRawOriginal('budget'),
            'deadline' => $project->calendarDeadline()?->toDateString() ?? (string) $project->getRawOriginal('deadline'),
        ];
    }
}
