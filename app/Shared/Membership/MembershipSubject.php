<?php

declare(strict_types=1);

namespace App\Shared\Membership;

/**
 * Display snapshot of one membership subject.
 *
 * Values only: no Eloquent model or relation crosses the membership boundary.
 */
final readonly class MembershipSubject
{
    public function __construct(
        public MembershipSubjectType $type,
        public string $id,
        public string $label,
        public ?string $publicUrl = null,
        public ?string $status = null,
        public ?string $imageUrl = null,
    ) {}
}
