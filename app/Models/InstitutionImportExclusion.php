<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable record that a source-backed institution was deleted and its
 * identity must never be recreated by a future import.
 *
 * Keyed by (source, external_ref). The institution_id is a nullable audit
 * reference to the deleted row without a foreign key; deleted_at records
 * when the source row was deleted. This row is independent of the
 * deleted_models snapshots, so snapshot pruning never re-opens the identity.
 *
 * Exclusions are only ever created by InstitutionImportExclusionObserver.
 * No importer currently reads them (the masjid feed importer was removed);
 * the rows are retained so a future importer can never reopen a deleted
 * identity, and clearing one requires explicit manual review.
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

    public static function forIdentity(string $source, string $externalRef): ?static
    {
        return static::query()
            ->where('source', $source)
            ->where('external_ref', $externalRef)
            ->first();
    }
}
