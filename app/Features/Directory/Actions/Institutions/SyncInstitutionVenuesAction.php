<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\InstitutionVenueRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Replace the institution's venue links with the given set.
 *
 * Payload booleans, roles, and target existence are validated before any
 * write; the institution row is locked and rechecked inside the transaction
 * so primary swaps commit atomically with no temporary one-primary-per-role
 * violation visible.
 */
final class SyncInstitutionVenuesAction
{
    use AsAction;

    public function __construct(private AddInstitutionVenueLinkAction $addLink) {}

    /**
     * @param  list<array{venue_id: string, role: InstitutionVenueRole|string, is_primary?: bool}>  $links
     */
    public function handle(Model $institution, array $links): void
    {
        DirectoryModel::assert($institution, 'institution');

        if (! $institution->exists) {
            throw ValidationException::withMessages([
                'institution_id' => __('The selected institution is invalid.'),
            ]);
        }

        $normalized = $this->normalizeLinks($links);
        $institutionId = (string) $institution->getKey();
        $venueIds = array_column($normalized, 'venue_id');

        DB::transaction(function () use ($institution, $institutionId, $venueIds, $normalized): void {
            $locked = DirectoryModel::query('institution')
                ->whereKey($institutionId)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Model) {
                throw ValidationException::withMessages([
                    'institution_id' => __('The selected institution is invalid.'),
                ]);
            }

            DB::table('institution_venue')
                ->where('institution_id', $institutionId)
                ->when($venueIds !== [], fn ($query) => $query->whereNotIn('venue_id', $venueIds))
                ->delete();

            foreach ($normalized as $link) {
                $this->addLink->handle(
                    $institutionId,
                    $link['venue_id'],
                    $link['role'],
                    $link['is_primary'],
                );
            }

            $institution->unsetRelation('venues');
            $institution->unsetRelation('operatedVenues');
            $institution->unsetRelation('preferredVenues');
        });
    }

    /**
     * @param  list<array{venue_id: string, role: InstitutionVenueRole|string, is_primary?: bool}>  $links
     * @return list<array{venue_id: string, role: InstitutionVenueRole, is_primary: bool}>
     */
    private function normalizeLinks(array $links): array
    {
        $normalized = [];
        $seenVenues = [];
        $primaryRoles = [];

        foreach (array_values($links) as $index => $link) {
            if (! is_array($link)) {
                throw ValidationException::withMessages([
                    "venues.{$index}" => __('The venue link is invalid.'),
                ]);
            }

            $venueId = $link['venue_id'] ?? null;

            if (! is_string($venueId) || ! Str::isUuid($venueId)) {
                throw ValidationException::withMessages([
                    "venues.{$index}.venue_id" => __('The selected venue is invalid.'),
                ]);
            }

            if (in_array($venueId, $seenVenues, true)) {
                throw ValidationException::withMessages([
                    "venues.{$index}.venue_id" => __('The venue is linked more than once.'),
                ]);
            }

            $seenVenues[] = $venueId;

            $role = $link['role'] ?? null;

            if (! $role instanceof InstitutionVenueRole) {
                $role = is_string($role) ? InstitutionVenueRole::tryFrom($role) : null;
            }

            if (! $role instanceof InstitutionVenueRole) {
                throw ValidationException::withMessages([
                    "venues.{$index}.role" => __('The selected venue role is invalid.'),
                ]);
            }

            // Strict booleans only: a (bool) cast would turn 'false' into true.
            $rawPrimary = $link['is_primary'] ?? null;

            if ($rawPrimary !== null && ! is_bool($rawPrimary)) {
                throw ValidationException::withMessages([
                    "venues.{$index}.is_primary" => __('The venue primary flag must be true or false.'),
                ]);
            }

            $isPrimary = $rawPrimary ?? false;

            if ($isPrimary) {
                if (in_array($role->value, $primaryRoles, true)) {
                    throw ValidationException::withMessages([
                        "venues.{$index}.is_primary" => __('Only one primary venue is allowed per role.'),
                    ]);
                }

                $primaryRoles[] = $role->value;
            }

            $normalized[] = [
                'venue_id' => $venueId,
                'role' => $role,
                'is_primary' => $isPrimary,
            ];
        }

        if ($seenVenues !== []) {
            $existingIds = DirectoryModel::query('venue')
                ->whereKey($seenVenues)
                ->pluck('id')
                ->all();

            $missing = array_diff($seenVenues, array_map(strval(...), $existingIds));

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'venues.0.venue_id' => __('The selected venue is invalid.'),
                ]);
            }
        }

        return $normalized;
    }
}
