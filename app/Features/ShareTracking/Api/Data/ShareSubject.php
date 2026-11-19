<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api\Data;

/**
 * Ownership-neutral identity of the subject a share outcome is recorded against.
 */
final readonly class ShareSubject
{
    public function __construct(
        public string $type,
        public ?string $id,
        public string $key,
        public ?string $title,
    ) {}

    public static function for(string $type, string $id, ?string $title = null): self
    {
        return new self(
            type: $type,
            id: $id,
            key: $type.':'.$id,
            title: is_string($title) && $title !== '' ? $title : null,
        );
    }
}
