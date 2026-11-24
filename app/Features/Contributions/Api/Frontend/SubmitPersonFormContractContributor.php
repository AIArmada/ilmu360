<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Frontend;

use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Enums\PersonNameType;
use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;

final readonly class SubmitPersonFormContractContributor implements FrontendFormContractContributor
{
    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::SUBMIT_PERSON;
    }

    public function manifest(?User $user): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context): array
    {
        return [
            'flow' => 'submit_person',
            'method' => 'POST',
            'endpoint' => route('api.client.contributions.persons.store'),
            'auth_required' => true,
            'defaults' => [
                'gender' => Gender::Male->value,
                'address' => [
                    'country_id' => null,
                    'area_assignments' => [],
                ],
            ],
            'fields' => [
                FrontendFormContractField::make('name', 'string', required: true, maxLength: 255),
                FrontendFormContractField::make('family_name', 'string', required: false, maxLength: 100),
                FrontendFormContractField::make('middle_name', 'string', required: false, maxLength: 100),
                FrontendFormContractField::make('title_ids', 'array<string>', required: false),
                FrontendFormContractField::make('names', 'array<object>', required: false, meta: [
                    'item_schema' => [
                        'type' => 'object',
                        'fields' => [
                            ['name' => 'name_type', 'type' => 'string', 'required' => true, 'allowed_values' => FrontendFormContractField::enumValues(PersonNameType::class)],
                            ['name' => 'full_name', 'type' => 'string', 'required' => true, 'max_length' => 255],
                            ['name' => 'language_code', 'type' => 'string', 'required' => false, 'max_length' => 10],
                            ['name' => 'is_primary', 'type' => 'boolean', 'required' => false],
                        ],
                    ],
                ]),
                FrontendFormContractField::make('gender', 'string', required: true, default: Gender::Male->value, allowedValues: FrontendFormContractField::enumValues(Gender::class)),
                FrontendFormContractField::make('bio', 'rich_text', required: false),
                FrontendFormContractField::make('institutions', 'array<object>', required: false, catalog: route('api.client.catalogs.submit-institutions'), meta: [
                    'item_schema' => [
                        'type' => 'object',
                        'fields' => [
                            ['name' => 'institution_id', 'type' => 'uuid', 'required' => true],
                            ['name' => 'position', 'type' => 'string', 'required' => false, 'max_length' => 255],
                            ['name' => 'is_primary', 'type' => 'boolean', 'required' => false],
                        ],
                    ],
                ]),
                FrontendFormContractField::make('address', 'object', required: true),
                FrontendFormContractField::make('address.country_id', 'uuid', required: true, catalog: route('api.client.catalogs.countries')),
                FrontendFormContractField::make('address.area_assignments', 'array<object>', required: false),
                FrontendFormContractField::make('language_ids', 'array<string>', required: false, catalog: route('api.client.catalogs.languages')),
                FrontendFormContractField::make('contactMethods', 'array<object>', required: false),
                FrontendFormContractField::make('social_media', 'array<object>', required: false),
                FrontendFormContractField::make('avatar', 'file', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb()),
                FrontendFormContractField::make('square', 'file', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), meta: ['required_aspect_ratio' => '1:1']),
                FrontendFormContractField::make('profile', 'file', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), meta: ['required_aspect_ratio' => '3:4']),
                FrontendFormContractField::make('gallery', 'array<file>', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb()),
            ],
            'conditional_rules' => [],
        ];
    }
}
