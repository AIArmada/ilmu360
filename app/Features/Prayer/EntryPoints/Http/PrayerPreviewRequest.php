<?php

declare(strict_types=1);

namespace App\Features\Prayer\EntryPoints\Http;

use App\Features\Prayer\Support\PrayerPreviewRules;
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
        return PrayerPreviewRules::inputs();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [PrayerPreviewRules::validateCalendarDate(...)];
    }
}
