<?php

declare(strict_types=1);

namespace App\Features\Reports\Api\Frontend;

use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;

final readonly class ReportFormContractContributor implements FrontendFormContractContributor
{
    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::REPORT;
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
            'flow' => 'report',
            'method' => 'POST',
            'endpoint' => route('api.reports.store'),
            'auth_required' => true,
            'fields' => [
                FrontendFormContractField::make('entity_type', 'string', required: true),
                FrontendFormContractField::make('entity_id', 'uuid', required: true),
                FrontendFormContractField::make('category', 'string', required: true),
                FrontendFormContractField::make('description', 'string', required: false, maxLength: 2000),
                FrontendFormContractField::make('evidence', 'array<file>', required: false, acceptedMimeTypes: FrontendFormContractField::evidenceMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), maxFiles: 8),
            ],
            'conditional_rules' => [
                ['field' => 'description', 'required_when' => ['category' => ['other']]],
            ],
        ];
    }
}
