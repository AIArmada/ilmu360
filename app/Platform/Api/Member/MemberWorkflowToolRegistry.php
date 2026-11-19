<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Platform\Api\Member\Contracts\MemberWorkflowToolContributor;

final class MemberWorkflowToolRegistry
{
    /** @var list<MemberWorkflowToolContributor> */
    private array $contributors = [];

    public function register(MemberWorkflowToolContributor $contributor): void
    {
        if (! in_array($contributor, $this->contributors, true)) {
            $this->contributors[] = $contributor;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        $contributors = $this->contributors;

        usort(
            $contributors,
            static fn (MemberWorkflowToolContributor $left, MemberWorkflowToolContributor $right): int => $left->priority() <=> $right->priority(),
        );

        $tools = [];

        foreach ($contributors as $contributor) {
            $tools = array_merge($tools, $contributor->tools());
        }

        return $tools;
    }
}
