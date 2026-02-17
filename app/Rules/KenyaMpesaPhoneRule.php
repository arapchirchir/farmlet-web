<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class KenyaMpesaPhoneRule implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $phone = preg_replace('/[^\d+]/', '', (string) $value);
        $normalized = ltrim($phone, '+');

        if (preg_match('/^(?:254|0)?(?:7\d{8}|1\d{8})$/', $normalized) === 1) {
            return;
        }

        $fail(__('Enter a valid Kenya MPESA phone number (07XXXXXXXX, 01XXXXXXXX, or 2547XXXXXXXX).'));
    }
}
