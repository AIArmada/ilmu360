<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api\Frontend;

use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;

final readonly class InstitutionWorkspaceFormContractContributor implements FrontendFormContractContributor
{
    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::INSTITUTION_WORKSPACE;
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
            'flow' => 'institution_workspace',
            'workspace_endpoint' => route('api.client.institution-workspace.show'),
            'member_add_endpoint_template' => route('api.client.institution-workspace.members.store', ['institutionId' => 'institution'], false),
            'member_update_endpoint_template' => route('api.client.institution-workspace.members.update', ['institutionId' => 'institution', 'memberId' => 'member'], false),
            'member_remove_endpoint_template' => route('api.client.institution-workspace.members.destroy', ['institutionId' => 'institution', 'memberId' => 'member'], false),
            'member_add_fields' => [
                FrontendFormContractField::make('email', 'email', required: true),
                FrontendFormContractField::make('role_id', 'string', required: true, catalog: route('api.client.catalogs.institution-roles')),
            ],
            'member_edit_fields' => [
                FrontendFormContractField::make('role_id', 'string', required: true, catalog: route('api.client.catalogs.institution-roles')),
            ],
        ];
    }
}
