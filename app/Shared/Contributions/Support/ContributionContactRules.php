<?php

declare(strict_types=1);

namespace App\Shared\Contributions\Support;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use Illuminate\Validation\Rule;

final class ContributionContactRules
{
    /** @return array<string, list<string|\Illuminate\Validation\Rules\Enum>> */
    public static function rules(): array
    {
        return [
            'contactMethods' => ['sometimes', 'nullable', 'array'],
            'contactMethods.*.type' => ['required_with:contactMethods.*.value', Rule::enum(ContactMethodType::class)],
            'contactMethods.*.value' => ['required_with:contactMethods.*.type', 'string', 'max:255'],
            'contactMethods.*.purpose' => ['nullable', Rule::enum(ContactPurpose::class)],
            'contactMethods.*.is_public' => ['nullable', 'boolean'],
            'social_media' => ['sometimes', 'nullable', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::enum(SocialPlatform::class)],
            'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
        ];
    }
}
