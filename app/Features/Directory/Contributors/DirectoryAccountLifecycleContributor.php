<?php

declare(strict_types=1);

namespace App\Features\Directory\Contributors;

use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Features\Directory\Api\SubmissionEligibilitySync;
use App\Features\Directory\Api\VenueWorkflow;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Preserves Directory verification attribution and submission eligibility
 * across account deletion and restoration.
 *
 * The five verifier links currently live on Directory-owned tables. They are
 * detached on account deletion and restored only when the column is still
 * null, so a record verified by someone else in the meantime is never
 * overwritten. Submission eligibility is recomputed after restore because it
 * depends on both the restored phone verification state and the restored
 * membership pivots.
 */
final readonly class DirectoryAccountLifecycleContributor implements AccountLifecycleContributor
{
    /**
     * @var array<string, array{string, string}>
     */
    private const array VERIFIER_LINKS = [
        'verified_reference_ids' => ['reference', 'verified_by'],
        'verified_venue_ids' => ['venue', 'verified_by'],
    ];

    public function __construct(
        private SubmissionEligibilitySync $eligibilitySync,
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
        $snapshot = [];

        $snapshot['verified_institution_ids'] = $this->verifiedIds('institution', 'verified_by', $accountId);
        $snapshot['verified_donation_channel_ids'] = $this->verifiedIds('donation_channel', 'verified_by', $accountId);
        $snapshot['verified_person_ids'] = $this->verifiedIds('person', 'verified_by', $accountId);

        foreach (self::VERIFIER_LINKS as $key => [$type, $column]) {
            $snapshot[$key] = $this->verifiedIds($type, $column, $accountId);
        }

        return $snapshot;
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

    /** @return list<string> */
    private function verifiedIds(string $type, string $column, string $accountId): array
    {
        $model = DirectoryModel::new($type);

        return DirectoryModel::query($type)
            ->where($column, $accountId)
            ->pluck($model->getKeyName())
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
