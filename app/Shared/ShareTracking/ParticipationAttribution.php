<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

/**
 * Records the share-attribution fact for a free event admission.
 *
 * Registration calls this inside its admission transaction so a created
 * registration and its frozen attribution decision commit together. The
 * implementation owns the canonical business key and always writes the
 * `registration_id` and `guest` metadata keys; callers may add business
 * metadata that cannot replace those canonical keys.
 */
interface ParticipationAttribution
{
    /**
     * @param  array<string, mixed>  $businessMetadata  Additional scalar business
     *                                                  metadata, for example `via_going`.
     */
    public function recordFreeRegistration(
        string $registrationId,
        string $eventId,
        ?string $actorId,
        bool $guest,
        ?object $request = null,
        array $businessMetadata = [],
    ): void;

    /**
     * Records a direct self-check-in outcome.
     *
     * The implementation owns the canonical business key, metadata keys and
     * occurrence time derived from the attendance record.
     */
    public function recordCheckIn(
        string $checkinId,
        string $eventId,
        ?string $actorId,
        ?object $request = null,
    ): void;

    /**
     * Records a self-check-in outcome that inherits provenance from its
     * registration instead of resolving a fresh request attribution.
     */
    public function recordInheritedCheckIn(
        string $checkinId,
        string $eventId,
        ?string $actorId,
        string $registrationId,
    ): void;
}
