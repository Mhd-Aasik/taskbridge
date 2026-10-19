<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class LkrBudget implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $value)) {
            $fail('Enter a budget in LKR with at most two decimal places, using digits and a decimal point only.');

            return;
        }

        // Compare integer digits, without converting money to floating point.
        $whole = ltrim(explode('.', $value, 2)[0], '0');
        if ($whole === '') {
            $fail('The budget must be at least LKR 1.00.');
        } elseif (strlen($whole) > 10) {
            $fail('The budget must not exceed LKR 9,999,999,999.99.');
        }
    }
}
