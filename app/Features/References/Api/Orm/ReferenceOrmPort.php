<?php

declare(strict_types=1);

namespace App\Features\References\Api\Orm;

use App\Features\References\Api\ReferenceQueries;

/**
 * Explicit Eloquent integration port for reference visibility constraints.
 *
 * @internal Consumers compose event/reference queries and need the owner's
 *           published-visibility predicate. Ordinary reference reads belong in
 *           ReferenceOrmPort.
 *
 * Consumers: Events schedule/detail pages, event payloads/submission sync,
 * Institutions and Person pages, and the References page.
 */
interface ReferenceOrmPort extends ReferenceQueries
{
    public function applyPublicVisibility(mixed $query, ?string $table = null): mixed;

    public function constrainEventReferenceSubtree(mixed $query, string $referenceIdColumn = 'references.id'): mixed;
}
