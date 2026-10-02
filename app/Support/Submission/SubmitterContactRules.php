<?php

namespace App\Support\Submission;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SubmitterContactRules
{
    /**
     * Mirror of the submitter phone input limit shared by the Livewire form and the API.
     */
    public const int PHONE_MAX_LENGTH = 20;

    public const int PHONE_MIN_DIGITS = 7;

    public const int PHONE_MAX_DIGITS = 15;

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function assertSubmitterContactsAreValid(array $validated, ?User $submitter, string $validationKeyPrefix = ''): void
    {
        $email = $validated['submitter_email'] ?? null;
        $phone = $validated['submitter_phone'] ?? null;

        if (! $submitter instanceof User && ! filled($validated['submitter_name'] ?? null)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('submitter_name', $validationKeyPrefix) => __('validation.required', ['attribute' => __('Nama Anda')]),
            ]);
        }

        if (! $submitter instanceof User && ! filled($email) && ! filled($phone)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('submitter_email', $validationKeyPrefix) => __('Either submitter email or submitter phone is required.'),
                SubmissionValues::prefixedKey('submitter_phone', $validationKeyPrefix) => __('Either submitter email or submitter phone is required.'),
            ]);
        }

        if (filled($email) && ! self::isValidEmail($email)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('submitter_email', $validationKeyPrefix) => __('Alamat e-mel tidak sah. Sila semak semula.'),
            ]);
        }

        if (filled($phone) && ! self::isValidPhone($phone)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('submitter_phone', $validationKeyPrefix) => __('Nombor telefon tidak sah. Sila semak semula.'),
            ]);
        }
    }

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
