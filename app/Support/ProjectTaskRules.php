<?php

namespace App\Support;

use App\Models\Milestone;
use App\Models\ProjectAssignment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProjectTaskRules
{
    public const FIELDS = ['title', 'description', 'due_date', 'milestone_id'];

    public static function milestoneId(array $input): ?int
    {
        $id = $input['milestone_id'] ?? null;
        if ($id === '') {
            $id = null;
        }
        $values = Validator::make(['milestone_id' => $id], ['milestone_id' => ['nullable', 'integer', 'min:1']])->validate();

        return $values['milestone_id'] === null ? null : (int) $values['milestone_id'];
    }

    public static function validatedDetails(ProjectAssignment $assignment, ?Milestone $milestone, array $input): array
    {
        $details = Arr::only($input, ['title', 'description', 'due_date']);
        foreach (['title', 'description'] as $field) {
            if (is_string($details[$field] ?? null)) {
                $details[$field] = Str::trim($details[$field]);
            }
        }
        $details['description'] = ($details['description'] ?? '') === '' ? null : $details['description'];
        $dateRules = ['bail', 'required', 'string', 'date_format:Y-m-d',
            'after_or_equal:'.$assignment->assigned_at->setTimezone('Asia/Colombo')->toDateString(),
            'before_or_equal:'.$assignment->agreed_deadline->toDateString()];
        if ($milestone) {
            $dateRules[] = 'before_or_equal:'.$milestone->due_date->toDateString();
        }

        return Validator::make($details, [
            'title' => ['bail', 'required', 'string', 'max:150', 'not_regex:/\A[\s\p{Z}]*\z/u'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => $dateRules,
        ], [
            'title.not_regex' => 'Enter a task title with more than whitespace.',
            'due_date.after_or_equal' => 'Choose a task date on or after the assignment date in Sri Lanka.',
            'due_date.before_or_equal' => 'The task date must be no later than the agreed completion date and its selected milestone date.',
        ])->validate();
    }
}
