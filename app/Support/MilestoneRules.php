<?php

namespace App\Support;

use App\Models\ProjectAssignment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MilestoneRules
{
    public const FIELDS = ['title', 'description', 'due_date'];

    public static function validatedDetails(ProjectAssignment $assignment, array $input): array
    {
        $details = Arr::only($input, self::FIELDS);
        foreach (['title', 'description'] as $field) {
            if (is_string($details[$field] ?? null)) {
                $details[$field] = Str::trim($details[$field]);
            }
        }
        if (($details['description'] ?? null) === '') {
            $details['description'] = null;
        }

        return Validator::make($details, [
            'title' => ['bail', 'required', 'string', 'max:150', 'not_regex:/\A[\s\p{Z}]*\z/u'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['bail', 'required', 'string', 'date_format:Y-m-d',
                'after_or_equal:'.$assignment->assigned_at->setTimezone('Asia/Colombo')->toDateString(),
                'before_or_equal:'.$assignment->agreed_deadline->toDateString()],
        ], [
            'title.not_regex' => 'Enter a milestone title with more than whitespace.',
            'due_date.after_or_equal' => 'Choose a date on or after the assignment date in Sri Lanka.',
            'due_date.before_or_equal' => 'Choose a date on or before the agreed completion date.',
        ])->validate();
    }
}
