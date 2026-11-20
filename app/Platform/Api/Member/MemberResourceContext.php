<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Platform\Api\Resources\ResourceDefinitionContext;
use App\Platform\Search\ResourceSearchDispatcher;

/**
 * Shared collaborators injected into every member resource definition.
 *
 * Keeps owner adapters to a two-argument constructor while the member engine
 * stays singleton-scoped on the Platform side.
 */
final readonly class MemberResourceContext
{
    public function __construct(
        public ResourceDefinitionContext $base,
        public MemberResourceDirectory $directory,
        public MemberResourceHookRegistry $hooks,
        public MemberResourceMetadataBuilder $metadata,
        public ResourceSearchDispatcher $search,
        public MemberResourceWriter $writer,
    ) {}
}
