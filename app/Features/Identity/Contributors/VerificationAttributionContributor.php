<?php

declare(strict_types=1);

namespace App\Features\Identity\Contributors;

use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\Identity\Api\AccountSubmissionEligibility;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\References\Api\ReferenceWorkflow;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Preserves Directory verification attribution and submission eligibility
 * across account deletion and restoration.
 *
 * The five verifier links are owned by their respective features. They are
 * detached on account deletion and restored only when the column is still
 * null, so a record verified by someone else in the meantime is never
 * overwritten. Submission eligibility is recomputed after restore because it
 * depends on both the restored phone verification state and the restored
 * membership pivots.
 */
final readonly class VerificationAttributionContributor implements AccountLifecycleContributor
{
    public function __construct(
        private AccountSubmissionEligibility $eligibilitySync,
        private InstitutionWorkflow $institutionWorkflow,
        private DonationChannelWorkflow $donationChannelWorkflow,
        private PersonWorkflow $personWorkflow,
        private ReferenceWorkflow $referenceWorkflow,
        private VenueWorkflow $venueWorkflow,
    ) {}

    public function key(): string
    {
        return 'directory';
    }

    public function priority(): int
    {
        return 80;
    }

    public function snapshot(string $accountId): array
    {
        return [
            'verified_institution_ids' => $this->institutionWorkflow->verifiedIdsForAccount($accountId),
            'verified_donation_channel_ids' => $this->donationChannelWorkflow->verifiedIdsForAccount($accountId),
            'verified_person_ids' => $this->personWorkflow->verifiedIdsForAccount($accountId),
            'verified_reference_ids' => $this->referenceWorkflow->verifiedIdsForAccount($accountId),
            'verified_venue_ids' => $this->venueWorkflow->verifiedIdsForAccount($accountId),
        ];
    }

    public function detach(string $accountId): void
    {
        $this->institutionWorkflow->detachVerifier($accountId);
        $this->donationChannelWorkflow->detachVerifier($accountId);
        $this->personWorkflow->detachVerifier($accountId);
        $this->referenceWorkflow->detachVerifier($accountId);
        $this->venueWorkflow->detachVerifier($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->institutionWorkflow->restoreVerifier($accountId, $this->stringIds($snapshot['verified_institution_ids'] ?? []));
        $this->donationChannelWorkflow->restoreVerifier($accountId, $this->stringIds($snapshot['verified_donation_channel_ids'] ?? []));
        $this->personWorkflow->restoreVerifier($accountId, $this->stringIds($snapshot['verified_person_ids'] ?? []));
        $this->referenceWorkflow->restoreVerifier($accountId, $this->stringIds($snapshot['verified_reference_ids'] ?? []));
        $this->venueWorkflow->restoreVerifier($accountId, $this->stringIds($snapshot['verified_venue_ids'] ?? []));
    }

    public function afterRestore(string $accountId): void
    {
        // Eligibility depends on the restored phone-verification state and the
        // membership pivots restored by the Membership contributor.
        $this->eligibilitySync->syncForAccount($accountId);
    }

    /**
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
