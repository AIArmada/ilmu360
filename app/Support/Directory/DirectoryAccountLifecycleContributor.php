<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonWorkflow;
use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Venue;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Directory\SubmissionEligibilitySync;

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
        'verified_reference_ids' => [Reference::class, 'verified_by'],
        'verified_venue_ids' => [Venue::class, 'verified_by'],
    ];

    public function __construct(
        private SubmissionEligibilitySync $eligibilitySync,
        private InstitutionWorkflow $institutionWorkflow,
        private DonationChannelWorkflow $donationChannelWorkflow,
        private PersonWorkflow $personWorkflow,
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

        $snapshot['verified_institution_ids'] = $this->stringIds(
            Institution::query()->where('verified_by', $accountId)->pluck('id')->all(),
        );
        $snapshot['verified_donation_channel_ids'] = $this->stringIds(
            DonationChannel::query()->where('verified_by', $accountId)->pluck('id')->all(),
        );
        $snapshot['verified_person_ids'] = $this->stringIds(
            Person::query()->where('verified_by', $accountId)->pluck('id')->all(),
        );

        foreach (self::VERIFIER_LINKS as $key => [$modelClass, $column]) {
            $snapshot[$key] = $modelClass::query()
                ->where($column, $accountId)
                ->pluck((new $modelClass)->getKeyName())
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        return $snapshot;
    }

    public function detach(string $accountId): void
    {
        $this->institutionWorkflow->detachVerifier($accountId);
        $this->donationChannelWorkflow->detachVerifier($accountId);
        $this->personWorkflow->detachVerifier($accountId);

        foreach (self::VERIFIER_LINKS as [$modelClass, $column]) {
            $modelClass::query()
                ->where($column, $accountId)
                ->update([$column => null]);
        }
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->institutionWorkflow->restoreVerifier($accountId, $this->stringIds($snapshot['verified_institution_ids'] ?? []));
        $this->donationChannelWorkflow->restoreVerifier($accountId, $this->stringIds($snapshot['verified_donation_channel_ids'] ?? []));
        $this->personWorkflow->restoreVerifier($accountId, $this->stringIds($snapshot['verified_person_ids'] ?? []));

        foreach (self::VERIFIER_LINKS as $key => [$modelClass, $column]) {
            $ids = $this->stringIds($snapshot[$key] ?? []);

            if ($ids === []) {
                continue;
            }

            $modelClass::query()
                ->whereKey($ids)
                ->whereNull($column)
                ->update([$column => $accountId]);
        }
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
