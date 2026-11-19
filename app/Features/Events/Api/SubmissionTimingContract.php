<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Public boundary for event submission timing and country-timezone policy.
 *
 * External consumers only need the country-timezone catalog used by the
 * public form contract and submission-country resolution. Strict date and
 * prayer clock derivation stays with the owner implementation.
 */
interface SubmissionTimingContract
{
    /**
     * Effective country id -> timezones catalog.
     *
     * @return array<string, list<string>>
     */
    public function countryTimezoneCatalog(): array;

    /**
     * Resolve a submission country id, optionally falling back to an ISO2
     * country code. Unknown values resolve null.
     */
    public function resolveSubmissionCountryId(mixed $countryId, ?string $fallbackCountryCode = null): ?string;
}
