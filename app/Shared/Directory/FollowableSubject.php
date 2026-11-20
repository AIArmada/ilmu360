<?php

declare(strict_types=1);

namespace App\Shared\Directory;

/**
 * Immutable identity of a public directory subject that supports following.
 *
 * Owners resolve their own slug/UUID lookups and publish whether the record is
 * publicly visible; engagement applies its role-based bypass around that flag.
 */
final readonly class FollowableSubject
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $type,
        public string $morphType,
        public bool $isPubliclyVisible,
    ) {}
}
