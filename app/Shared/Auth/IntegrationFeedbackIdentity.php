<?php

declare(strict_types=1);

namespace App\Shared\Auth;

final readonly class IntegrationFeedbackIdentity
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isAdmin,
        public bool $hasMemberMcpAccess,
    ) {}
}
