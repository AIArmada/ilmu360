<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable record that a source-backed institution was deleted and must never
 * be recreated by the feed importer.
 *
 * Keyed by (source, external_ref). The institution_id is a nullable audit
 * reference to the deleted row without a foreign key; deleted_at records
 * when the source row was deleted. This row is independent of the
 * deleted_models snapshots, so snapshot pruning never re-opens the identity.
 *
 * Exclusions are only ever created by InstitutionImportExclusionObserver and
 * read by the importer. Clearing one requires explicit manual review; the
 * importer never clears or overwrites an exclusion.
 */
class InstitutionImportExclusion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'institution_import_exclusions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'external_ref',
        'institution_id',
        'deleted_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether an exclusion row exists for the identity right now.
     *
     * Explicitly impure: concurrent committed deletes can insert an
     * exclusion row between two identical calls, so callers recheck after
     * locking and both checks are meaningful.
     *
     * @phpstan-impure
     */
    public static function excludes(string $source, string $externalRef): bool
    {
        return static::query()
            ->where('source', $source)
            ->where('external_ref', $externalRef)
            ->exists();
    }

    public static function forIdentity(string $source, string $externalRef): ?static
    {
        return static::query()
            ->where('source', $source)
            ->where('external_ref', $externalRef)
            ->first();
    }
}
