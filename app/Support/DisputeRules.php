<?php

namespace App\Support;

use App\Enums\DisputeDecision;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DisputeRules
{
    public static function opening(array $input): array
    {
        return Validator::make(['title' => self::trim($input['title'] ?? null), 'reason' => self::trim($input['reason'] ?? null), 'confirmed' => $input['confirmed'] ?? false], [
            'title' => self::text(150), 'reason' => self::text(5000), 'confirmed' => ['required', 'accepted'],
        ])->validate();
    }

    public static function statement(array $input): string
    {
        return Validator::make(['body' => self::trim($input['body'] ?? null)], ['body' => self::text(5000)])->validate()['body'];
    }

    public static function resolution(array $input): array
    {
        return Validator::make(['decision' => $input['decision'] ?? null, 'rationale' => self::trim($input['rationale'] ?? null), 'confirmed' => $input['confirmed'] ?? false], [
            'decision' => ['bail', 'required', 'string', Rule::enum(DisputeDecision::class)],
            'rationale' => self::text(5000), 'confirmed' => ['required', 'accepted'],
        ])->validate();
    }

    public static function token(string $token): string
    {
        return Validator::make(['submission_id' => Str::lower($token)], ['submission_id' => ['required', 'uuid']])->validate()['submission_id'];
    }

    private static function trim(mixed $value): mixed
    {
        return is_string($value) ? Str::trim($value) : $value;
    }

    private static function text(int $max): array
    {
        return ['required', 'string', 'max:'.$max, 'not_regex:/\A[\s\p{Z}\p{C}]*\z/u'];
    }
}
