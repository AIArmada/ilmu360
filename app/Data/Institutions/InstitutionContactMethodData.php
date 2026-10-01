<?php

declare(strict_types=1);

namespace App\Data\Institutions;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Data\InstitutionData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class InstitutionContactMethodData extends Data
{
    public function __construct(
        public ContactMethodType $type,
        public string $value,
        public ContactPurpose $purpose = ContactPurpose::General,
        public ?string $label = null,
        public bool $is_primary = false,
        public bool $is_public = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ContactMethodType::class)],
            'value' => ['required', 'string', 'max:255', InstitutionData::nonBlankRule()],
            'purpose' => ['sometimes', Rule::enum(ContactPurpose::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['boolean'],
            'is_public' => ['boolean'],
        ];
    }
}
