<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\FacilityAvailability;
use AIArmada\Events\Models\FacilityType;
use AIArmada\Events\Models\VenueFacility;
use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Replace the general (venue-wide) facility rows of a venue.
 *
 * Space-scoped facility rows are preserved, matching the package contract.
 */
final readonly class SyncVenueFacilitiesAction
{
    /**
     * @param  array<int, array{code: string, availability?: FacilityAvailability|string, visibility?: string}>  $facilities
     */
    public function handle(Model $venue, array $facilities): int
    {
        DirectoryModel::assert($venue, 'venue');

        // Venue facilities are global catalog rows shared across owners, so
        // the sync runs in explicit global context.
        return OwnerContext::withOwner(null, fn (): int => $this->sync($venue, $facilities));
    }

    /**
     * @param  array<int, array{code: string, availability?: FacilityAvailability|string, visibility?: string}>  $facilities
     */
    private function sync(Model $venue, array $facilities): int
    {
        // An empty payload never reaches the model guards, so validate the
        // target explicitly before mutating anything.
        if ($venue->getKey() === null || ! DirectoryModel::query('venue')->whereKey($venue->getKey())->exists()) {
            throw new InvalidArgumentException('A persisted venue is required to sync venue facilities.');
        }

        $codes = array_unique(array_map(fn (array $f): string => $f['code'], $facilities));

        /** @var Collection<string, FacilityType> $types */
        $types = FacilityType::query()
            ->where('is_active', true)
            ->whereIn('code', $codes)
            ->get()
            ->keyBy(fn (FacilityType $t): string => $t->code);

        $unknown = array_diff($codes, $types->keys()->all());

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown facility codes: '.implode(', ', $unknown));
        }

        $synced = 0;

        DB::transaction(function () use ($venue, $facilities, $types, &$synced): void {
            $existing = VenueFacility::query()
                ->where('venue_id', $venue->getKey())
                ->whereNull('venue_space_id')
                ->get()
                ->keyBy(fn (VenueFacility $vf): string => $vf->facility_type_id);

            $incomingIds = [];

            foreach ($facilities as $facility) {
                $type = $types[$facility['code']];

                VenueFacility::query()->updateOrCreate(
                    [
                        'venue_id' => $venue->getKey(),
                        'facility_type_id' => $type->getKey(),
                        'venue_space_id' => null,
                    ],
                    [
                        'availability' => $facility['availability'] ?? 'available',
                        'visibility' => $facility['visibility'] ?? 'public',
                        'quantity' => null,
                        'capacity' => null,
                        'is_free' => null,
                        'fee_amount' => null,
                        'currency' => null,
                        'location_label' => null,
                        'notes' => null,
                        'verified_at' => null,
                    ],
                );

                $incomingIds[] = $type->getKey();
                $synced++;
            }

            $toRemove = $existing->keys()->diff($incomingIds);

            if ($toRemove->isNotEmpty()) {
                // Delete through the model so model hooks run; only venue-wide
                // rows of this venue are candidates, space rows survive.
                VenueFacility::query()
                    ->where('venue_id', $venue->getKey())
                    ->whereNull('venue_space_id')
                    ->whereIn('facility_type_id', $toRemove->all())
                    ->get()
                    ->each(fn (VenueFacility $row): mixed => $row->delete());
            }
        });

        return $synced;
    }
}
