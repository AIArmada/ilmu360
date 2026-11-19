<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation\Contracts;

use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;

/**
 * Contributes the schemas and operation patches owned by one feature area.
 *
 * Contributors are applied in registry order. `supports()` lets a projected or
 * filtered document opt out of an owner surface; the published public contract
 * includes every registered owner.
 */
interface DocumentSchemaContributor
{
    public function supports(OpenApi $document, OpenApiContext $context): bool;

    /**
     * @return array<string, Schema> Schema name to schema definition.
     */
    public function schemas(Components $components): array;

    public function patch(OpenApi $document, Components $components): void;
}
