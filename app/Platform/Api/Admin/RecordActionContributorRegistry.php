<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin;

use App\Platform\Api\Admin\Contracts\RecordActionContributor;

final class RecordActionContributorRegistry
{
    /** @var list<RecordActionContributor> */
    private array $contributors = [];

    public function register(RecordActionContributor $contributor): void
    {
        if (! in_array($contributor, $this->contributors, true)) {
            $this->contributors[] = $contributor;
        }
    }

    /** @return list<RecordActionContributor> */
    public function all(): array
    {
        return $this->contributors;
    }
}
