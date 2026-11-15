<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

final readonly class MembershipApplicationEvidence
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
    ) {}

    /**
     * @return array{id: string, name: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
        ];
    }
}
