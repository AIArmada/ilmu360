<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Venue;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Directory\SubmissionEligibilitySync;
use Illuminate\Database\Eloquent\Model;

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
     * @var array<string, array{class-string<Model>, string}>
     */
    private const array VERIFIER_LINKS = [
        'verified_donation_channel_ids' => [DonationChannel::class, 'verified_by'],
        'verified_person_ids' => [Person::class, 'verified_by'],
        'verified_institution_ids' => [Institution::class, 'verified_by'],
        'verified_reference_ids' => [Reference::class, 'verified_by'],
        'verified_venue_ids' => [Venue::class, 'verified_by'],
    ];

    public function __construct(
        private SubmissionEligibilitySync $eligibilitySync,
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
        foreach (self::VERIFIER_LINKS as [$modelClass, $column]) {
            $modelClass::query()
                ->where($column, $accountId)
                ->update([$column => null]);
        }
    }

    public function restore(string $accountId, array $snapshot): void
    {
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
