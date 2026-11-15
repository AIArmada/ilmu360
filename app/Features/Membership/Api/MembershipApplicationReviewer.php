<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

final readonly class MembershipApplicationReviewer
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $email,
    ) {}

    /**
     * @return array{id: string, name: string|null, email: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
