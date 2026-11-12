<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Persons\Enums\AffiliationType;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Auditing\RecordsCustomAuditDifferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Synchronize a person's institution affiliations and the primary invariant.
 *
 * Also serves the Filament relation-manager attach/edit/detach operations so
 * affiliation pivot writes never run outside Directory ownership.
 */
final readonly class SyncPersonAffiliationsAction
{
    /**
     * @param  mixed  $institutionsPayload  List of affiliation rows.
     */
    public function handle(Model $person, mixed $institutionsPayload): void
    {
        $beforeAffiliations = $this->auditState($person);
        $current = $this->affiliationsQuery($person)->get();
        $currentIds = $current
            ->pluck('institution_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $rows = is_array($institutionsPayload) ? $institutionsPayload : [];
        $requestedIds = collect($rows)
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->map(static fn (array $row): ?string => is_string($row['institution_id'] ?? null) ? trim($row['institution_id']) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $knownInstitutions = DirectoryModel::query('institution')
            ->whereKey($requestedIds)
            ->get()
            ->keyBy(static fn (Model $institution): string => (string) $institution->getKey());

        $retainedIds = [];
        $primaryId = null;

        $this->affiliationsQuery($person)->update(['is_primary' => false, 'updated_at' => now()]);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $institutionId = is_string($row['institution_id'] ?? null) ? trim($row['institution_id']) : null;

            if ($institutionId === null || $institutionId === '') {
                continue;
            }

            $institutionExists = in_array($institutionId, $currentIds, true)
                || $knownInstitutions->has($institutionId);

            if (! $institutionExists) {
                continue;
            }

            $retainedIds[] = $institutionId;

            if ($primaryId === null && (bool) ($row['is_primary'] ?? false)) {
                $primaryId = $institutionId;
            }

            $position = $this->normalizeOptionalString($row['position'] ?? null);

            if (in_array($institutionId, $currentIds, true)) {
                $this->affiliationsQuery($person)
                    ->where('institution_id', $institutionId)
                    ->update(['position' => $position, 'updated_at' => now()]);

                continue;
            }

            $this->affiliationsQuery($person)->create($this->affiliationAttributes($person, $institutionId, [
                'position' => $position,
                'is_primary' => false,
            ]));
        }

        if ($primaryId === null && $retainedIds !== []) {
            $primaryId = $retainedIds[0];
        }

        if ($primaryId !== null) {
            $this->affiliationsQuery($person)
                ->where('institution_id', $primaryId)
                ->update(['is_primary' => true, 'updated_at' => now()]);
        }

        $delete = $this->affiliationsQuery($person);

        if ($retainedIds === []) {
            $delete->delete();
        } else {
            $delete->whereNotIn('institution_id', $retainedIds)->delete();
        }

        $this->recordAudit($person, $beforeAffiliations);
    }

    /**
     * Preserve the legacy single-affiliation payload used by older forms.
     *
     * @param  array<string, mixed>  $payload
     */
    public function syncSingle(Model $person, array $payload): void
    {
        $institutionId = array_key_exists('institution_id', $payload)
            ? $this->normalizeOptionalString($payload['institution_id'])
            : null;
        $institutionPosition = array_key_exists('institution_position', $payload)
            ? $this->normalizeOptionalString($payload['institution_position'])
            : null;

        if ($institutionId === null && $institutionPosition === null) {
            return;
        }

        $beforeAffiliations = $this->auditState($person);
        $currentAffiliation = $this->currentAffiliation($person);
        $currentInstitutionId = $currentAffiliation instanceof Model
            ? (string) $currentAffiliation->getAttribute('institution_id')
            : null;

        $institutionId = array_key_exists('institution_id', $payload)
            ? $this->normalizeOptionalString($payload['institution_id'])
            : $currentInstitutionId;

        if ($institutionId === null) {
            if ($currentAffiliation instanceof Model) {
                $this->affiliationsQuery($person)
                    ->where('institution_id', $currentAffiliation->getAttribute('institution_id'))
                    ->delete();
            }

            $this->recordAudit($person, $beforeAffiliations);

            return;
        }

        $position = array_key_exists('institution_position', $payload)
            ? $this->normalizeOptionalString($payload['institution_position'])
            : $this->normalizeOptionalString($currentAffiliation?->getAttribute('position'));

        if ($currentAffiliation instanceof Model && (string) $currentAffiliation->getAttribute('institution_id') !== $institutionId) {
            $this->affiliationsQuery($person)
                ->where('institution_id', $currentAffiliation->getAttribute('institution_id'))
                ->delete();
        }

        $this->affiliationsQuery($person)
            ->where('institution_id', '!=', $institutionId)
            ->update(['is_primary' => false, 'updated_at' => now()]);

        $existing = $this->affiliationsQuery($person)
            ->where('institution_id', $institutionId)
            ->first();

        if ($existing instanceof Model) {
            $existing->update([
                'position' => $position,
                'is_primary' => true,
            ]);
        } else {
            $this->affiliationsQuery($person)->create($this->affiliationAttributes($person, $institutionId, [
                'position' => $position,
                'is_primary' => true,
            ]));
        }

        $this->recordAudit($person, $beforeAffiliations);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function attach(Model $person, string $institutionId, array $attributes): string
    {
        $isPrimary = (bool) ($attributes['is_primary'] ?? false);

        if ($isPrimary) {
            $this->affiliationsQuery($person)->update(['is_primary' => false, 'updated_at' => now()]);
        }

        $affiliation = $this->affiliationsQuery($person)->create($this->affiliationAttributes($person, $institutionId, $attributes));

        return (string) $affiliation->getKey();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Model $person, string $affiliationId, array $attributes): void
    {
        $affiliation = $this->affiliationsQuery($person)->find($affiliationId);

        if (! $affiliation instanceof Model) {
            return;
        }

        $this->updateRecord($person, $affiliation, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateForInstitution(Model $person, string $institutionId, array $attributes): void
    {
        $affiliation = $this->affiliationsQuery($person)
            ->where('institution_id', $institutionId)
            ->first();

        if ($affiliation instanceof Model) {
            $this->updateRecord($person, $affiliation, $attributes);

            return;
        }

        $this->attach($person, $institutionId, $attributes);
    }

    /**
     * @param  list<string>  $institutionIds
     */
    public function detach(Model $person, array $institutionIds): int
    {
        if ($institutionIds === []) {
            return 0;
        }

        return $this->affiliationsQuery($person)
            ->whereIn('institution_id', $institutionIds)
            ->delete();
    }

    /**
     * @param  list<string>  $affiliationIds
     */
    public function deleteRecords(Model $person, array $affiliationIds): int
    {
        if ($affiliationIds === []) {
            return 0;
        }

        return $this->affiliationsQuery($person)
            ->whereKey($affiliationIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateRecord(Model $person, Model $affiliation, array $attributes): void
    {
        if ((bool) ($attributes['is_primary'] ?? false)) {
            $this->affiliationsQuery($person)
                ->whereKeyNot($affiliation->getKey())
                ->update(['is_primary' => false, 'updated_at' => now()]);
        }

        $affiliation->update($this->affiliationAttributes(
            $person,
            (string) $affiliation->getAttribute('institution_id'),
            $attributes,
            $affiliation,
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function affiliationAttributes(Model $person, string $institutionId, array $attributes, ?Model $existing = null): array
    {
        $type = $attributes['affiliation_type'] ?? $existing?->getAttribute('affiliation_type') ?? AffiliationType::Member;

        return array_filter([
            'affiliatable_type' => $existing?->getAttribute('affiliatable_type') ?? $person->getMorphClass(),
            'affiliatable_id' => $existing?->getAttribute('affiliatable_id') ?? $person->getKey(),
            'institution_id' => $institutionId,
            'affiliation_type' => $type instanceof AffiliationType ? $type->value : (string) $type,
            'position' => array_key_exists('position', $attributes)
                ? $this->normalizeOptionalString($attributes['position'])
                : $existing?->getAttribute('position'),
            'joined_at' => array_key_exists('joined_at', $attributes) ? $attributes['joined_at'] : $existing?->getAttribute('joined_at'),
            'left_at' => array_key_exists('left_at', $attributes) ? $attributes['left_at'] : $existing?->getAttribute('left_at'),
            'is_primary' => (bool) ($attributes['is_primary'] ?? $existing?->getAttribute('is_primary') ?? false),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return Builder<\App\Models\Affiliation>
     */
    private function affiliationsQuery(Model $person): Builder
    {
        return DirectoryModel::query('affiliation')
            ->where('affiliatable_id', $person->getKey())
            ->where('affiliatable_type', $person->getMorphClass());
    }

    private function currentAffiliation(Model $person): ?Model
    {
        $affiliation = $this->affiliationsQuery($person)
            ->orderByDesc('is_primary')
            ->orderBy('institution_id')
            ->first();

        return $affiliation instanceof Model ? $affiliation : null;
    }

    /**
     * @return list<array{id: string, name: string, position: ?string, is_primary: bool}>
     */
    private function auditState(Model $person): array
    {
        $institutionNames = DirectoryModel::query('institution')
            ->whereKey(
                $this->affiliationsQuery($person)
                    ->pluck('institution_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all(),
            )
            ->pluck('name', 'id');

        /** @var Collection<int, array{id: string, name: string, position: ?string, is_primary: bool}> $state */
        $state = $this->affiliationsQuery($person)
            ->orderByDesc('is_primary')
            ->get()
            ->map(static function (Model $affiliation) use ($institutionNames): array {
                $institutionId = (string) $affiliation->getAttribute('institution_id');

                return [
                    'id' => $institutionId,
                    'name' => (string) ($institutionNames[$institutionId] ?? ''),
                    'position' => $affiliation->getAttribute('position'),
                    'is_primary' => (bool) $affiliation->getAttribute('is_primary'),
                ];
            })
            ->sortBy([
                ['is_primary', 'desc'],
                ['name', 'asc'],
            ])
            ->values();

        return $state->all();
    }

    /**
     * @param  list<array{id: string, name: string, position: ?string, is_primary: bool}>  $beforeAffiliations
     */
    private function recordAudit(Model $person, array $beforeAffiliations): void
    {
        $afterAffiliations = $this->auditState($person);

        if ($beforeAffiliations === $afterAffiliations || ! $person instanceof RecordsCustomAuditDifferences) {
            return;
        }

        $person->recordCustomAuditDifferences(
            'sync',
            ['institutions' => $beforeAffiliations],
            ['institutions' => $afterAffiliations],
        );
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
