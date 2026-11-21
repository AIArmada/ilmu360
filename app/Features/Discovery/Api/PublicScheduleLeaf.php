<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

use Carbon\CarbonImmutable;

/**
 * Public-schedule leaf time contract.
 *
 * The schedule owner assembles leaves; callers that only order or split them
 * by time depend on these accessors instead of the concrete leaf value.
 */
interface PublicScheduleLeaf
{
    public function id(): string;

    public function title(): string;

    public function eventId(): string;

    public function eventStatus(): string;

    /**
     * @return list<string>
     */
    public function sessionIds(): array;

    public function startsAt(): ?CarbonImmutable;

    public function endsAt(): ?CarbonImmutable;

    /**
     * Rebuild this leaf with the given session subset, preserving order.
     *
     * @param  list<string>  $sessionIds
     */
    public function withSessions(array $sessionIds): self;
}
