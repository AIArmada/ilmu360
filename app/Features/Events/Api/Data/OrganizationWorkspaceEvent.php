<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

final readonly class OrganizationWorkspaceEvent
{
    public function __construct(
        public string $id,
        public string $title,
        public string $status,
        public string $visibility,
        public bool $publiclyReachable,
        public ?string $publicUrl,
        public bool $canEdit,
        public bool $canManageAdmissions,
    ) {}
}
