<?php

namespace App\Support;

use App\Models\Skill;
use App\Rules\LkrBudget;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FreelancerProfileRules
{
    public static function rules(): array
    {
        return [
            'headline' => ['required', 'string', 'max:120'],
            'bio' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:120'],
            'portfolio_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'hourly_rate' => ['bail', 'nullable', 'string', 'max:13', new LkrBudget(allowZero: true)],
            'skill_ids' => ['required', 'array', 'min:1', 'max:10'],
            'skill_ids.*' => ['required', 'integer', 'distinct', Rule::exists(Skill::class, 'id')],
        ];
    }

    public static function validate(array $input): array
    {
        foreach (['headline', 'bio', 'location', 'portfolio_url'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::trim($input[$field]);
            }
        }

        return Validator::make($input, self::rules())->validate();
    }
}
