<?php

namespace App\Features\Prayer\Services;

use AIArmada\Addressing\Models\Address;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Contracts\ResolvesEventTimeExpression;
use AIArmada\Events\Data\EventTimeExpressionData;
use AIArmada\Events\Features\Catalog\Models\EventSession;
use AIArmada\Events\Features\Venues\Models\EventLocation;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Institutions\Models\Institution;
use App\Features\Places\Models\Venue;
use App\Features\Prayer\Api\PrayerProviderRegistry;
use App\Features\Prayer\Api\PrayerQuery;
use App\Features\Prayer\Api\PrayerReference;
use App\Features\Prayer\Api\ResolvePrayerAnchorAction;
use App\Features\Prayer\Support\PrayerLocation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

readonly class PrayerTimeExpressionResolver implements ResolvesEventTimeExpression
{
    public function __construct(
        private ResolvePrayerAnchorAction $anchors,
        private PrayerProviderRegistry $providers,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function resolve(EventTimeExpressionData $expression, array $context = []): ?DateTimeInterface
    {
        if ($expression->anchorType !== 'prayer' || $expression->anchorCode === null) {
            return null;
        }

        $prayerReference = PrayerReference::tryFrom($expression->anchorCode);

        if ($prayerReference === null) {
            return null;
        }

        $event = app(EventAuthoring::class)->eventPrayerScope($expression->eventId);

        if ($event === null) {
            return null;
        }

        // Storage keeps magnitude in offset_minutes and the sign in relation;
        // reconstruct signed minutes so every offset case round-trips.
        $offsetMinutes = abs($expression->offsetMinutes ?? 0);
        $signedMinutes = $expression->relation === 'before' ? -$offsetMinutes : $offsetMinutes;

        // Provenance-bearing expressions re-resolve through the provider
        // contract on their own scoped prayer day and location: offsets can
        // roll the parent start past midnight, and sessions carry dates
        // independent of the parent event. Anything unresolvable — missing
        // or invalid provenance, disabled providers, cold cache,
        // unmappable country — keeps the persisted instant: the submit
        // path already resolved it from provider data or the hardcoded
        // estimates, and re-resolution must never invent another clock.
        $provenance = is_array($expression->metadata) ? ($expression->metadata['prayer'] ?? null) : null;

        if (is_array($provenance) && config('prayer.enabled')) {
            $scoped = $this->resolveFromProvenance($expression, $event, $provenance, $prayerReference, $signedMinutes);

            if ($scoped instanceof CarbonImmutable) {
                return $scoped;
            }
        }

        return $expression->resolvedStartsAt;
    }

    /**
     * @param  array{timezone: string, venue_id: string|null, institution_id: string|null, persisted_address_id: string|null}  $event
     * @param  array<string, mixed>  $provenance
     */
    private function resolveFromProvenance(EventTimeExpressionData $expression, array $event, array $provenance, PrayerReference $prayerReference, int $signedMinutes): ?CarbonImmutable
    {
        $date = app(EventAuthoring::class)->normalizePrayerDateString($provenance['prayer_date'] ?? null);

        if ($date === null) {
            return null;
        }

        $scope = OwnerContext::withOwner(null, fn (): array => $this->resolveScope($expression, $event, $provenance));

        $inputs = PrayerLocation::fromAddress($scope['address']);

        // The calculation identity is pinned at submission: re-reading
        // the country from the current address would mix a new country
        // (an organizer address corrected since) with the original
        // zone/coords. Provenance without a coherent country keeps the
        // persisted instant — no identity is reconstructible.
        $countryCode = is_string($provenance['country'] ?? null) ? strtoupper(trim($provenance['country'])) : '';

        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            return null;
        }

        $resolver = $this->providers->zoneResolverFor($countryCode);

        // The persisted zone produced the original clock, and the persisted
        // coordinates are the exact cache inputs submission read (genuine
        // GPS or zone representatives). Reusing both keeps re-resolution
        // stable instead of re-running zone matching (whose GPS memo may
        // have moved on). Only zone-less rows resolve fresh — and keep the
        // returned coordinates, since those are what submission queried.
        $zone = $provenance['zone'] ?? null;
        $zone = is_string($zone) && trim($zone) !== '' ? trim($zone) : null;

        [$latitude, $longitude] = $this->provenanceCoords($provenance);

        if ($zone === null) {
            $resolved = $resolver->resolve(
                $inputs['latitude'],
                $inputs['longitude'],
                $inputs['stateCode'],
                $inputs['districtCandidates'],
            );
            $zone = $resolved['zone'];
            $latitude ??= $resolved['lat'];
            $longitude ??= $resolved['lng'];
        }

        $latitude ??= $inputs['latitude'];
        $longitude ??= $inputs['longitude'];

        if (($latitude === null || $longitude === null) && $zone !== null) {
            // Rows without pinned coordinates fall back to the persisted
            // zone's representatives instead of querying the no-coords
            // cell submission never read.
            $representatives = $resolver->coordsForZone($zone);
            $latitude ??= $representatives['lat'] ?? null;
            $longitude ??= $representatives['lng'] ?? null;
        }

        $methods = $this->providers->methodsFor($countryCode);

        try {
            $anchor = $this->anchors->handle(
                new PrayerQuery(
                    $countryCode,
                    $date,
                    $scope['timezone'],
                    $latitude,
                    $longitude,
                    $zone,
                    $methods['ummah'],
                    $methods['madhab'],
                ),
                $prayerReference,
                false,
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_array($anchor) || ! isset($anchor['instant']) || ! is_string($anchor['instant'])) {
            return null;
        }

        try {
            $instant = CarbonImmutable::parse($anchor['instant'], 'UTC');
        } catch (Throwable) {
            return null;
        }

        // Offset math runs on the anchor instant — never on reconstructed
        // wall text — so DST folds cannot shift the re-resolved start.
        return $instant->addMinutes($signedMinutes);
    }

    /**
     * Session expressions resolve in the session's own timezone and
     * location; event-level expressions use the parent event.
     *
     * @param  array{timezone: string, venue_id: string|null, institution_id: string|null, persisted_address_id: string|null}  $event
     * @param  array<string, mixed>  $provenance
     * @return array{timezone: string, address: ?Address}
     */
    private function resolveScope(EventTimeExpressionData $expression, array $event, array $provenance): array
    {
        $eventTimezone = $event['timezone'] !== '' ? $event['timezone'] : 'Asia/Kuala_Lumpur';

        $session = $expression->sessionId === null
            ? null
            : EventSession::query()->find($expression->sessionId);

        if ($expression->sessionId !== null && ! $session instanceof EventSession) {
            return ['timezone' => $eventTimezone, 'address' => null];
        }

        $timezone = $session instanceof EventSession && is_string($session->timezone) && $session->timezone !== ''
            ? $session->timezone
            : $eventTimezone;

        // Submission targets first: the same institution-first + venue +
        // owner-borrow selection submission used, so re-resolution reads
        // the same address even for online sessions that persist no
        // locations. Rows without pinned targets fall through to the
        // persisted-location reads below.
        $targetAddress = PrayerLocation::forTargets(
            $this->provenanceId($provenance['venue_id'] ?? null),
            $this->provenanceId($provenance['institution_id'] ?? null),
        );

        if ($targetAddress instanceof Address) {
            return ['timezone' => $timezone, 'address' => $targetAddress];
        }

        if (! $session instanceof EventSession) {
            return ['timezone' => $timezone, 'address' => $this->eventPersistedAddress($event)];
        }

        $location = $session->locations()->where('location_role', 'primary')->first()
            ?? $session->locations()->first();

        if (! $location instanceof EventLocation) {
            return ['timezone' => $timezone, 'address' => null];
        }

        return [
            'timezone' => $timezone,
            'address' => $this->sessionLocationAddress($location),
        ];
    }

    /**
     * @param  array{timezone: string, venue_id: string|null, institution_id: string|null, persisted_address_id: string|null}  $event
     */
    private function eventPersistedAddress(array $event): ?Address
    {
        // Rows without pinned targets read the persisted location first,
        // then the submission selector so a venue without its own
        // address still borrows its owner's.
        $address = $event['persisted_address_id'] !== null
            ? Address::query()->find($event['persisted_address_id'])
            : null;

        return $address instanceof Address
            ? $address
            : PrayerLocation::forTargets($event['venue_id'], $event['institution_id']);
    }

    private function sessionLocationAddress(EventLocation $location): ?Address
    {
        $locationable = $location->locationable;

        // Routed through the submission selector so a venue without its
        // own address borrows its owner's — the same approximation
        // submission resolved — instead of collapsing to the persisted
        // instant.
        if ($locationable instanceof Institution) {
            return PrayerLocation::forTargets(null, (string) $locationable->getKey());
        }

        if ($locationable instanceof Venue) {
            return PrayerLocation::forTargets((string) $locationable->getKey(), null);
        }

        if (is_string($location->venue_id) && $location->venue_id !== '') {
            return PrayerLocation::forTargets($location->venue_id, null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $provenance
     * @return array{0: float|null, 1: float|null}
     */
    private function provenanceCoords(array $provenance): array
    {
        $latitude = $provenance['lat'] ?? null;
        $longitude = $provenance['lng'] ?? null;

        // Both-or-neither: a half-persisted pair must not mix submission
        // coordinates with live address coordinates from another source.
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [null, null];
        }

        return [(float) $latitude, (float) $longitude];
    }

    private function provenanceId(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
