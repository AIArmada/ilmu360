<?php

namespace App\Models;

use App\Enums\InstitutionVenueRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Institution↔venue bridge row.
 *
 * Draft integrity policy, enforced here so direct relation attach/sync,
 * updateExistingPivot, and custom pivot creation cannot bypass what
 * SyncInstitutionVenuesAction validates:
 *
 * - role is a valid InstitutionVenueRole (enum or backing string);
 * - is_primary is a real boolean (never a string like 'false');
 * - institution_id and venue_id reference existing rows;
 * - at most one primary per institution and role: saving a primary demotes
 *   the previous primary of the same role.
 *
 * Persistence is self-contained: every save runs in its own transaction
 * under an owning-institution row lock, so a failed insert rolls the demote
 * back and concurrent swaps serialize without callers opening transactions.
 */
class InstitutionVenue extends Pivot
{
    use HasUuids;

    protected $table = 'institution_venue';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'venue_id',
        'role',
        'is_primary',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'role' => InstitutionVenueRole::class,
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[\Override]
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => $this->saveWithinInstitutionLock($options));
    }

    /**
     * Lock the owning institution row, recheck both link targets, then run
     * the ordinary save (whose saving hook validates and demotes) inside the
     * same transaction.
     *
     * @param  array<string, mixed>  $options
     */
    private function saveWithinInstitutionLock(array $options): bool
    {
        $institutionId = $this->getAttributes()['institution_id'] ?? null;

        if (is_string($institutionId) && Str::isUuid($institutionId)) {
            $locked = Institution::query()->whereKey($institutionId)->lockForUpdate()->first();

            if (! $locked instanceof Institution) {
                throw new InvalidArgumentException('The institution venue link references a missing institution.');
            }

            $venueId = $this->getAttributes()['venue_id'] ?? null;

            // Lock the target venue row in the same transaction: a concurrent
            // venue delete commits its bridge cleanup with the venue row, so
            // an unlocked existence check could pass before the delete commits
            // and leave a dangling bridge insertion.
            $lockedVenue = is_string($venueId) && Str::isUuid($venueId)
                ? Venue::query()->whereKey($venueId)->lockForUpdate()->first()
                : null;

            if (! $lockedVenue instanceof Venue) {
                throw new InvalidArgumentException('The institution venue link references a missing venue.');
            }
        }

        return parent::save($options);
    }

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            $attributes = $pivot->getAttributes();

            $role = $attributes['role'] ?? null;

            if (! $role instanceof InstitutionVenueRole) {
                $role = is_string($role) ? InstitutionVenueRole::tryFrom($role) : null;
            }

            if (! $role instanceof InstitutionVenueRole) {
                throw new InvalidArgumentException('The institution venue role is invalid.');
            }

            $pivot->setAttribute('role', $role);

            // Accept hydrated 0/1 alongside booleans, but never strings:
            // (bool) 'false' is true, which silently inverts intent.
            $rawPrimary = $attributes['is_primary'] ?? null;

            if ($rawPrimary === null) {
                $isPrimary = false;
            } elseif (is_bool($rawPrimary)) {
                $isPrimary = $rawPrimary;
            } elseif ($rawPrimary === 0 || $rawPrimary === 1) {
                $isPrimary = (bool) $rawPrimary;
            } else {
                throw new InvalidArgumentException('The institution venue primary flag must be a boolean.');
            }

            $pivot->setAttribute('is_primary', $isPrimary);

            $institutionId = $attributes['institution_id'] ?? null;
            $venueId = $attributes['venue_id'] ?? null;

            if (! is_string($institutionId) || ! Str::isUuid($institutionId) || ! Institution::query()->whereKey($institutionId)->exists()) {
                throw new InvalidArgumentException('The institution venue link references a missing institution.');
            }

            if (! is_string($venueId) || ! Str::isUuid($venueId) || ! Venue::query()->whereKey($venueId)->exists()) {
                throw new InvalidArgumentException('The institution venue link references a missing venue.');
            }

            if ($isPrimary) {
                static::query()
                    ->where('institution_id', $institutionId)
                    ->where('role', $role->value)
                    ->where('is_primary', true)
                    ->when($pivot->exists, fn ($query) => $query->whereKeyNot($pivot->getKey()))
                    ->update(['is_primary' => false]);
            }
        });
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
