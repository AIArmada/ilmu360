<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;

final class DocumentSchemaContributorRegistry
{
    /** @var list<DocumentSchemaContributor> */
    private array $contributors = [];

    public function register(DocumentSchemaContributor $contributor): void
    {
        if (in_array($contributor, $this->contributors, true)) {
            return;
        }

        $this->contributors[] = $contributor;
    }

    /**
     * @return list<DocumentSchemaContributor>
     */
    public function all(): array
    {
        return $this->contributors;
    }
}
