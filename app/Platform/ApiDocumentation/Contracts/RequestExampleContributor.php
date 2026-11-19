<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation\Contracts;

/**
 * Publishes request-body examples keyed by lowercase HTTP method and path,
 * for example `post auth/login`.
 */
interface RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array;
}
