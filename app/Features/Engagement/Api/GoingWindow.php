<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Event-level going eligibility window.
 *
 * The effective end is `ends_at` when present, falling back to `starts_at`.
 * Grouped public submissions use the latest effective occurrence end so an
 * ongoing grouped event remains going-eligible until its last eligible
 * occurrence ends, matching the schedule end-instant rule from D1c.
 */
final readonly class GoingWindow
{
    public function __construct(
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
    ) {}

    public static function from(?DateTimeInterface $startsAt, ?DateTimeInterface $endsAt): self
    {
        return new self(
            self::immutable($startsAt),
            self::immutable($endsAt),
        );
    }

    /**
     * @param  iterable<DateTimeInterface|null>  $occurrenceEffectiveEnds
     */
    public static function grouped(
        ?DateTimeInterface $startsAt,
        ?DateTimeInterface $endsAt,
        iterable $occurrenceEffectiveEnds,
    ): self {
        $latest = null;

        foreach ($occurrenceEffectiveEnds as $effectiveEnd) {
            $effectiveEnd = self::immutable($effectiveEnd);

            if (! $effectiveEnd instanceof DateTimeImmutable) {
                continue;
            }

            if (! $latest instanceof DateTimeImmutable || $effectiveEnd > $latest) {
                $latest = $effectiveEnd;
            }
        }

        if ($latest instanceof DateTimeImmutable) {
            return self::from($startsAt, $latest);
        }

        return self::from($startsAt, $endsAt);
    }

    public function effectiveEnd(): ?DateTimeImmutable
    {
        return $this->endsAt ?? $this->startsAt;
    }

    public function isOpenAt(DateTimeInterface $moment): bool
    {
        $effectiveEnd = $this->effectiveEnd();

        if (! $effectiveEnd instanceof DateTimeImmutable) {
            return true;
        }

        return $effectiveEnd >= self::immutable($moment);
    }

    public function hasEndedAt(DateTimeInterface $moment): bool
    {
        return ! $this->isOpenAt($moment);
    }

    private static function immutable(?DateTimeInterface $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        return DateTimeImmutable::createFromInterface($value);
    }
}
