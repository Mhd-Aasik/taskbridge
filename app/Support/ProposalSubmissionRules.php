<?php

namespace App\Support;

use App\Models\Project;
use App\Rules\LkrBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProposalSubmissionRules
{
    public const FIELDS = ['cover_letter', 'proposed_budget', 'proposed_deadline'];

    public static function earliestDeadline(): string
    {
        return CarbonImmutable::today('Asia/Colombo')->addDay()->toDateString();
    }

    public static function unavailableReason(Project $project): ?string
    {
        if (! Project::query()->discoverable()->whereKey($project->id)->exists()) {
            return 'This project is no longer available for proposals. It must be open, published, in an active category, and within its deadline.';
        }
        if (! $project->calendarDeadline() || $project->calendarDeadline()->toDateString() < self::earliestDeadline()) {
            return 'There is no valid completion date left. Completion must be tomorrow or later in Sri Lanka, and no later than the project deadline.';
        }

        return null;
    }

    public static function validatedDetails(Project $project, array $input): array
    {
        $details = Arr::only($input, self::FIELDS);
        if (is_string($details['cover_letter'] ?? null)) {
            $details['cover_letter'] = Str::trim($details['cover_letter']);
        }
        $details = Validator::make($details, [
            'cover_letter' => ['bail', 'required', 'string', 'max:5000', 'not_regex:/\A[\s\p{Z}]*\z/u'],
            'proposed_budget' => ['bail', 'required', 'string', 'max:13', new LkrBudget],
            'proposed_deadline' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after:'.CarbonImmutable::today('Asia/Colombo')->toDateString(), 'before_or_equal:'.$project->calendarDeadline()->toDateString()],
        ], [
            'cover_letter.not_regex' => 'Write a cover letter with more than whitespace.',
            'proposed_deadline.after' => 'Choose a completion date tomorrow or later in Sri Lanka (Asia/Colombo).',
            'proposed_deadline.before_or_equal' => 'Your completion date must be no later than the project deadline.',
        ])->validate();

        [$whole, $fraction] = array_pad(explode('.', $details['proposed_budget'], 2), 2, '');
        $details['proposed_budget'] = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');

        return $details;
    }
}
