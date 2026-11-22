<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Support\InstitutionFacilities;
use InvalidArgumentException;

/** Prepare canonical institution provenance, facilities and status fields before persistence. */
final readonly class PrepareInstitutionPersistenceAction
{
    public function handle(Institution $institution): void
    {
        if ($institution->exists) {
            // Create-only provenance: every existing row keeps its stored
            // identity bytes, so a manual row never acquires provenance
            // later and an imported row never changes identity.
            foreach (['source', 'external_ref', 'imported_at'] as $provenanceKey) {
                if ($institution->isDirty($provenanceKey)) {
                    $institution->setAttribute($provenanceKey, $institution->getOriginal($provenanceKey));
                }
            }
        } else {
            $this->assertValidProvenancePair($institution);

            if ($institution->getAttribute('source') !== null && $institution->getAttribute('imported_at') === null) {
                $institution->setAttribute('imported_at', now());
            }
        }

        if ($institution->isDirty('facilities')) {
            $normalized = InstitutionFacilities::normalize($institution->getAttribute('facilities'));

            $institution->setAttribute('facilities', $normalized === [] ? null : $normalized);
        }

        $rawStatus = $institution->getAttributes()['status'] ?? null;

        if ($rawStatus === null && ! $institution->exists) {
            $institution->setAttribute('status', InstitutionStatus::Pending);
            $rawStatus = InstitutionStatus::Pending->value;
        }

        $status = $rawStatus instanceof InstitutionStatus
            ? $rawStatus
            : InstitutionStatus::tryFrom((string) $rawStatus);

        if (! $status instanceof InstitutionStatus) {
            throw new InvalidArgumentException(sprintf('The institution status [%s] is invalid.', (string) $rawStatus));
        }

        if ($institution->isDirty('status')) {
            $now = now();

            if ($institution->exists) {
                // Every real transition records its timestamp anew so a later
                // inactivity cycle never reuses an older transition time.
                $institution->setAttribute('last_state_change_at', $now);

                match ($status) {
                    InstitutionStatus::Pending => null,
                    InstitutionStatus::Verified => $institution->setAttribute('verified_at', $now),
                    InstitutionStatus::Rejected => $institution->setAttribute('rejected_at', $now),
                    InstitutionStatus::Inactive => $institution->setAttribute('inactive_at', $now),
                };
            } else {
                // Creation preserves explicitly provided fixture timestamps.
                if ($institution->getAttribute('last_state_change_at') === null) {
                    $institution->setAttribute('last_state_change_at', $now);
                }

                match ($status) {
                    InstitutionStatus::Pending => null,
                    InstitutionStatus::Verified => $institution->getAttribute('verified_at') ?? $institution->setAttribute('verified_at', $now),
                    InstitutionStatus::Rejected => $institution->getAttribute('rejected_at') ?? $institution->setAttribute('rejected_at', $now),
                    InstitutionStatus::Inactive => $institution->getAttribute('inactive_at') ?? $institution->setAttribute('inactive_at', $now),
                };
            }

            // Review flags belong to the latest inactivity cycle only.
            $institution->stale_inactive_flagged_at = null;

            if ($status === InstitutionStatus::Verified) {
                $institution->verified_by ??= auth()->id();
            }
        }
    }

    /**
     * Strict source-pair validation: each key is null or a non-empty string,
     * and both keys are set together. Identity bytes are never normalized.
     */
    private function assertValidProvenancePair(Institution $institution): void
    {
        $source = $institution->getAttribute('source');
        $externalRef = $institution->getAttribute('external_ref');

        foreach (['source' => $source, 'external_ref' => $externalRef] as $key => $value) {
            if ($value !== null && (! is_string($value) || $value === '')) {
                throw new InvalidArgumentException("The institution {$key} must be a non-empty string or null.");
            }
        }

        if (($source !== null) !== ($externalRef !== null)) {
            throw new InvalidArgumentException('The institution source and external ref must be set together.');
        }
    }
}
