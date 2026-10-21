<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Models\ProjectAssignment;
use App\Rules\LkrBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentRules
{
    public static function reportDetails(array $input): array
    {
        $details = Arr::only($input, ['method', 'paid_on', 'reference', 'note', 'confirmed']);
        foreach (['reference', 'note'] as $field) {
            $details[$field] = self::normalizedText($details[$field] ?? null);
        }

        return Validator::make($details, [
            'method' => ['bail', 'required', 'string', Rule::enum(PaymentMethod::class)],
            'paid_on' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:2000'],
            'confirmed' => ['required', 'accepted'],
        ], ['confirmed.accepted' => 'Confirm the full external payment before recording it.'])->validate();
    }

    public static function receiptDetails(array $input): array
    {
        return Validator::make(['note' => self::normalizedText($input['note'] ?? null), 'confirmed' => $input['confirmed'] ?? false], [
            'note' => ['nullable', 'string', 'max:2000'],
            'confirmed' => ['required', 'accepted'],
        ], ['confirmed.accepted' => 'Confirm that you received the reported full payment.'])->validate();
    }

    public static function agreedAmount(ProjectAssignment $assignment, string $paidOn): string
    {
        $validated = Validator::make(['agreed_amount' => (string) $assignment->getRawOriginal('agreed_budget'), 'paid_on' => $paidOn], [
            'agreed_amount' => ['bail', 'required', 'string', 'max:13', new LkrBudget],
            'paid_on' => ['after_or_equal:'.$assignment->assigned_at->setTimezone('Asia/Colombo')->toDateString(), 'before_or_equal:'.CarbonImmutable::today('Asia/Colombo')->toDateString()],
        ], ['paid_on.after_or_equal' => 'Payment cannot be dated before the assignment date in Sri Lanka.', 'paid_on.before_or_equal' => 'Payment cannot be dated after today in Sri Lanka.'])->validate();

        return $validated['agreed_amount'];
    }

    private static function normalizedText(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = Str::trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
