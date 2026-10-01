<?php

declare(strict_types=1);

namespace App\Data\Institutions;

use App\Data\InstitutionData;
use App\Enums\InstitutionNameType;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class InstitutionNameData extends Data
{
    public function __construct(
        public string $full_name,
        public InstitutionNameType $name_type = InstitutionNameType::Nickname,
        public string $language_code = 'ms',
        public bool $is_primary = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255', InstitutionData::nonBlankRule()],
            'name_type' => ['sometimes', Rule::enum(InstitutionNameType::class)],
            'language_code' => ['sometimes', 'string', 'max:10'],
            'is_primary' => ['boolean'],
        ];
    }
}
