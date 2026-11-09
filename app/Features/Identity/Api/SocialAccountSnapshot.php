<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

final readonly class SocialAccountSnapshot
{
    public function __construct(
        public string $id,
        public string $accountId,
        public string $provider,
        public string $providerId,
        public ?string $avatarUrl,
    ) {}
}
