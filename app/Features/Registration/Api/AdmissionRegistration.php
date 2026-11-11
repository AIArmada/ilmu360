<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Public free-admission registration boundary.
 *
 * Callers outside Registration pass stable scalar identities and receive only
 * immutable scalar results, so the feature can move its models and vendor
 * integration without leaking mutable Eloquent state across the boundary.
 *
 * The returned registration list preserves the admission writer's order; the
 * primary registration is the first row created (or replayed) by the call.
 * `created` reports whether this call created the row; idempotent replays
 * return `false` and never re-freeze share provenance.
 */
interface AdmissionRegistration
{
    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @param  array<string, mixed>  $options  Admission options passed to the vendor
     *                                         free-registration writer; `attribution_metadata`
     *                                         adds caller-specific share-outcome metadata.
     * @return array{
     *     registrations: list<array{registration_id: string, created: bool}>,
     *     primary_registration_id: string|null
     * }
     */
    public function registerFree(
        string $eventId,
        array $participants,
        ?string $registrantId,
        array $options = [],
        ?object $request = null,
    ): array;
}
