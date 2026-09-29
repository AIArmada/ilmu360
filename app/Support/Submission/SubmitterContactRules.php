<?php

namespace App\Support\Submission;

use Illuminate\Support\Facades\Validator;

final class SubmitterContactRules
{
    /**
     * Mirror of the submitter phone input limit shared by the Livewire form and the API.
     */
    public const int PHONE_MAX_LENGTH = 20;

    public const int PHONE_MIN_DIGITS = 7;

    public const int PHONE_MAX_DIGITS = 15;

    public static function isValidEmail(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > 255) {
            return false;
        }

        return Validator::make(['value' => $value], ['value' => 'email'])->passes();
    }

    public static function isValidPhone(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > self::PHONE_MAX_LENGTH) {
            return false;
        }

        if (preg_match('/^\+?[\d\s\-.()]+$/', $value) !== 1) {
            return false;
        }

        $body = ltrim($value, '+');
        $startsWell = $body !== '' && (ctype_digit($body[0]) || str_starts_with($body, '('));
        $endsWithDigit = ctype_digit(substr($value, -1));

        if (! $startsWell || ! $endsWithDigit) {
            return false;
        }

        $digits = (string) preg_replace('/\D+/', '', $value);
        $digitCount = strlen($digits);

        return $digitCount >= self::PHONE_MIN_DIGITS && $digitCount <= self::PHONE_MAX_DIGITS;
    }
}
