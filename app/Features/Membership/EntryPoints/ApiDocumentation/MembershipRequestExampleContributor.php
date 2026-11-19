<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class MembershipRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'post membership-applications/{subjectType}/{subject}' => [
                'justification' => 'I am part of the mosque committee and can help manage event records for this institution.',
                'evidence' => ['committee-letter.pdf', 'staff-pass.jpg'],
            ],
        ];
    }
}
