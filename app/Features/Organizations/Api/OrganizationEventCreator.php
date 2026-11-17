<?php

declare(strict_types=1);

namespace App\Features\Organizations\Api;

/** Organization-owned authorization and event creation coordination. */
interface OrganizationEventCreator
{
    /** @param array<string, mixed> $form */
    public function create(string $actorId, string $organizationId, array $form): string;
}
