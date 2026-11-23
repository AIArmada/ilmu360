<?php

declare(strict_types=1);

namespace App\Features\Prayer\Support;

use Illuminate\Validation\Validator;

final class PrayerPreviewRules
{
    /** @return array<string, list<string>> */
    public static function inputs(): array
    {
        return [
            'country' => ['sometimes', 'string', 'size:2'],
            'date' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'zone' => ['sometimes', 'string', 'max:10'],
            'district' => ['sometimes', 'string', 'max:100'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'state' => ['sometimes', 'string', 'max:10'],
        ];
    }

    public static function validateCalendarDate(Validator $validator): void
    {
        $date = $validator->getData()['date'] ?? null;

        if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1 && ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            $validator->errors()->add('date', 'The date must be a valid calendar date.');
        }
    }
}
