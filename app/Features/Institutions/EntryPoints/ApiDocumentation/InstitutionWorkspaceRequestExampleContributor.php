<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class InstitutionWorkspaceRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'post institution-workspace/{institutionId}/members' => [
                'email' => 'member@example.com',
                'role_id' => 'institution_admin',
            ],
            'put institution-workspace/{institutionId}/members/{memberId}' => [
                'role_id' => 'institution_editor',
            ],
        ];
    }
}
