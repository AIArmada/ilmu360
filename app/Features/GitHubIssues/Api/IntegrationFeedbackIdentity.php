<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\Api;

final readonly class IntegrationFeedbackIdentity
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isAdmin,
        public bool $hasMemberMcpAccess,
    ) {}
}
