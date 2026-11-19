<?php

declare(strict_types=1);

namespace App\Features\Membership\Api\Frontend;

use App\Features\Membership\Api\MemberSubjectType;
use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;
use InvalidArgumentException;

final readonly class MembershipApplicationFormContractContributor implements FrontendFormContractContributor
{
    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::MEMBERSHIP_APPLICATION;
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
        $subjectType = $context['subjectType'] ?? null;

        if (! $subjectType instanceof MemberSubjectType) {
            throw new InvalidArgumentException('The membership application form contract requires a member subject type.');
        }

        return [
            'flow' => 'membership_application',
            'method' => 'POST',
            'endpoint_template' => route('api.client.membership-applications.store', [
                'subjectType' => $subjectType->publicRouteSegment(),
                'subject' => 'subject',
            ], false),
            'auth_required' => true,
            'fields' => [
                FrontendFormContractField::make('justification', 'string', required: true, maxLength: 2000),
                FrontendFormContractField::make('evidence', 'array<file>', required: true, acceptedMimeTypes: FrontendFormContractField::evidenceMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), maxFiles: 8),
            ],
        ];
    }
}
