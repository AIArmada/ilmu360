<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use Carbon\CarbonImmutable;

final readonly class MemberSubjectRecord
{
    public function __construct(
        public MemberSubjectType $type,
        public string $id,
        public string $name,
        public string $status,
        public ?CarbonImmutable $startsAt = null,
        public ?string $institutionName = null,
        public ?string $venueName = null,
    ) {}
}
