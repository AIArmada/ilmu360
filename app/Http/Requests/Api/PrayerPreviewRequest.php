<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PrayerPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
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

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $data = $validator->getData();
                $date = is_string($data['date'] ?? null) ? $data['date'] : '';

                if ($date !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1 && ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
                    $validator->errors()->add('date', 'The date must be a valid calendar date.');
                }
            },
        ];
    }
}
