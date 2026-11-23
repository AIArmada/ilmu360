<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Frontend;

use App\Features\Institutions\Api\Enums\InstitutionType;
use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;

final readonly class SubmitInstitutionFormContractContributor implements FrontendFormContractContributor
{
    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::SUBMIT_INSTITUTION;
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
            'flow' => 'submit_institution',
            'method' => 'POST',
            'endpoint' => route('api.client.contributions.institutions.store'),
            'auth_required' => true,
            'defaults' => [
                'address' => [
                    'country_id' => null,
                    'state_id' => null,
                    'city_id' => null,
                    'area_assignments' => [],
                    'line1' => null,
                    'line2' => null,
                    'postcode' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'google_place_id' => null,
                ],
            ],
            'fields' => [
                FrontendFormContractField::make('name', 'string', required: false, maxLength: 255),
                FrontendFormContractField::make('middle_name', 'string', required: false, maxLength: 100),
                FrontendFormContractField::make('family_name', 'string', required: false, maxLength: 100),
                FrontendFormContractField::make('names', 'array', required: false, meta: [
                    'item_schema' => [
                        'type' => 'object',
                        'fields' => [
                            ['name' => 'full_name', 'type' => 'string', 'required' => true, 'max_length' => 255],
                            ['name' => 'name_type', 'type' => 'string', 'required' => false, 'allowed_values' => ['official', 'nickname', 'abbreviation', 'local', 'historical']],
                            ['name' => 'language_code', 'type' => 'string', 'required' => false],
                            ['name' => 'is_primary', 'type' => 'boolean', 'required' => false],
                        ],
                    ],
                ]),
                FrontendFormContractField::make('type', 'string', required: false, allowedValues: FrontendFormContractField::enumValues(InstitutionType::class), meta: [
                    'description' => 'If omitted, the type is inferred from the verified Google Maps place when possible.',
                ]),
                FrontendFormContractField::make('description', 'rich_text', required: false),
                FrontendFormContractField::make('address', 'object', required: true),
                FrontendFormContractField::make('address.google_place_id', 'string', required: true),
                FrontendFormContractField::make('address.country_id', 'uuid', required: false, catalog: route('api.client.catalogs.countries')),
                FrontendFormContractField::make('address.state_id', 'uuid', required: false, catalog: route('api.client.catalogs.states')),
                FrontendFormContractField::make('address.city_id', 'uuid', required: false, catalog: route('api.client.catalogs.cities')),
                FrontendFormContractField::make('contactMethods', 'array<object>', required: false),
                FrontendFormContractField::make('social_media', 'array<object>', required: false),
                FrontendFormContractField::make('logo', 'file', required: false, acceptedMimeTypes: [...FrontendFormContractField::imageMimeTypes(), 'image/svg+xml'], maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb()),
                FrontendFormContractField::make('cover', 'file', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), meta: ['required_aspect_ratio' => '16:9']),
                FrontendFormContractField::make('gallery', 'array<file>', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), maxFiles: 10),
            ],
            'conditional_rules' => [
                ['field' => 'address.google_place_id', 'required' => true],
            ],
        ];
    }
}
