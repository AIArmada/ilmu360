<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Persons\Enums\PersonNameType;
use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Synchronize a person's alternate names and their single-primary invariant.
 */
final readonly class SyncPersonNamesAction
{
    /**
     * @param  mixed  $namesPayload  List of name rows from a contribution/admin payload.
     */
    public function handle(Model $person, mixed $namesPayload): void
    {
        $personId = (string) $person->getKey();
        $existing = DirectoryModel::query('person_name')
            ->where('person_id', $personId)
            ->get()
            ->keyBy(static fn (Model $name): string => (string) $name->getKey());
        $retainedIds = [];
        $primarySelected = false;

        foreach (is_array($namesPayload) ? $namesPayload : [] as $namePayload) {
            if (! is_array($namePayload)) {
                continue;
            }

            $fullName = trim((string) ($namePayload['full_name'] ?? ''));

            if ($fullName === '') {
                continue;
            }

            $nameType = $namePayload['name_type'] ?? PersonNameType::Display->value;
            $nameType = $nameType instanceof PersonNameType ? $nameType->value : (string) $nameType;
            $isPrimary = (bool) ($namePayload['is_primary'] ?? false) && ! $primarySelected;

            if ($isPrimary) {
                $primarySelected = true;
            }

            $nameId = is_string($namePayload['id'] ?? null) ? $namePayload['id'] : null;
            $name = $nameId !== null ? $existing->get($nameId) : null;
            $attributes = [
                'name_type' => $nameType,
                'full_name' => $fullName,
                'language_code' => trim((string) ($namePayload['language_code'] ?? 'ms')) ?: 'ms',
                'is_primary' => $isPrimary,
            ];

            if ($name instanceof Model) {
                $name->update($attributes);
                $retainedIds[] = (string) $name->getKey();
            } else {
                $created = DirectoryModel::query('person_name')->create([
                    'person_id' => $personId,
                    ...$attributes,
                ]);
                $retainedIds[] = (string) $created->getKey();
            }
        }

        $this->deleteMissing($existing, $retainedIds);
    }

    /**
     * Save a single name row (Filament names relation manager).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function save(Model $person, array $attributes, ?string $nameId = null): string
    {
        $personId = (string) $person->getKey();
        $existing = $nameId !== null
            ? DirectoryModel::query('person_name')->where('person_id', $personId)->find($nameId)
            : null;

        if ($existing instanceof Model) {
            $existing->update($attributes);

            return (string) $existing->getKey();
        }

        $created = DirectoryModel::query('person_name')->create([
            'person_id' => $personId,
            ...$attributes,
        ]);

        return (string) $created->getKey();
    }

    /**
     * @param  list<string>  $nameIds
     */
    public function delete(Model $person, array $nameIds): int
    {
        if ($nameIds === []) {
            return 0;
        }

        return DirectoryModel::query('person_name')
            ->where('person_id', (string) $person->getKey())
            ->whereKey($nameIds)
            ->delete();
    }

    /**
     * @param  Collection<string, Model>  $existing
     * @param  list<string>  $retainedIds
     */
    private function deleteMissing(Collection $existing, array $retainedIds): void
    {
        $existing
            ->reject(static fn (Model $name): bool => in_array((string) $name->getKey(), $retainedIds, true))
            ->each(static fn (Model $name): ?bool => $name->delete());
    }
}
