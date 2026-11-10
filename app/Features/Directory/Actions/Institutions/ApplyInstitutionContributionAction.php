<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Shared\Directory\Enums\InstitutionType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Apply an approved contribution update to an existing institution.
 */
final readonly class ApplyInstitutionContributionAction
{
    public function __construct(
        private GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private SyncInstitutionNamesAction $syncInstitutionNames,
        private SyncInstitutionRelationsAction $syncInstitutionRelations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Changed attributes.
     */
    public function handle(Model $institution, array $payload): array
    {
        $institution->fill([
            'name' => $payload['name'] ?? $institution->getAttribute('name'),
            'type' => array_key_exists('type', $payload)
                ? $this->normalizeType($payload['type'])
                : ($institution->getAttribute('type') instanceof BackedEnum ? $institution->getAttribute('type')->value : $institution->getAttribute('type')),
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $institution->getAttribute('description'),
        ]);

        $dirty = $institution->getDirty();
        $institution->save();

        if (array_key_exists('names', $payload) && is_array($payload['names'])) {
            $this->syncInstitutionNames->handle($institution, $payload['names']);
        }

        $this->syncInstitutionRelations->handle($institution, $payload);
        $this->generateInstitutionSlugAction->syncInstitutionSlug($institution);

        return $dirty;
    }

    private function normalizeType(mixed $value): string
    {
        if ($value instanceof InstitutionType) {
            return $value->value;
        }

        if (is_string($value) && InstitutionType::tryFrom($value) instanceof InstitutionType) {
            return $value;
        }

        return InstitutionType::Masjid->value;
    }
}
