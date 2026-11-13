<?php

declare(strict_types=1);

namespace App\Features\Rewards\Api;

/**
 * Immutable member enrollment projection.
 *
 * Carries the scalar identities root transport needs after enroll, leave and
 * reacceptance: the enrollment id, the optional acceptance id, the program and
 * member identities, the enrollment status and the accepted material terms
 * hash. No model or framework runtime type crosses the boundary.
 */
final readonly class MemberEnrollmentSnapshot
{
    public function __construct(
        public string $enrollmentId,
        public ?string $acceptanceId,
        public string $programId,
        public string $memberId,
        public string $status,
        public ?string $materialTermsHash = null,
    ) {}
}
