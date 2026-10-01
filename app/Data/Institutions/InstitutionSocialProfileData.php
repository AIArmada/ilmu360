<?php

declare(strict_types=1);

namespace App\Data\Institutions;

use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class InstitutionSocialProfileData extends Data
{
    public function __construct(
        public SocialPlatform $platform,
        public ?string $url = null,
        public ?string $handle = null,
        public ContactPurpose $purpose = ContactPurpose::General,
        public ?string $label = null,
        public bool $is_primary = false,
        public bool $is_public = true,
    ) {}

    /**
     * Each row must carry a valid URL or a non-blank handle; a row with
     * neither is rejected. The sibling check reads this row's relative
     * payload: required_without references resolve against the root
     * payload, so they never see siblings inside a collection item.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        $payload = is_array($context->payload) ? $context->payload : [];

        return [
            'platform' => ['required', Rule::enum(SocialPlatform::class)],
            'url' => ['nullable', 'url', 'max:2048', Rule::requiredIf(fn (): bool => blank($payload['handle'] ?? null))],
            'handle' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn (): bool => blank($payload['url'] ?? null))],
            'purpose' => ['sometimes', Rule::enum(ContactPurpose::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['boolean'],
            'is_public' => ['boolean'],
        ];
    }
}
