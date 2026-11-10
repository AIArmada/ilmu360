<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Persons\Enums\Gender;
use Illuminate\Database\Eloquent\Model;

/**
 * Apply an approved contribution update to an existing person.
 */
final readonly class ApplyPersonContributionAction
{
    public function __construct(
        private GeneratePersonSlugAction $generatePersonSlugAction,
        private SyncPersonRelationsAction $syncPersonRelations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Changed attributes.
     */
    public function handle(Model $person, array $payload): array
    {
        $person->fill([
            'name' => $payload['name'] ?? $person->getAttribute('name'),
            'middle_name' => array_key_exists('middle_name', $payload) ? $payload['middle_name'] : $person->getAttribute('middle_name'),
            'family_name' => array_key_exists('family_name', $payload) ? $payload['family_name'] : $person->getAttribute('family_name'),
            'gender' => array_key_exists('gender', $payload)
                ? $this->normalizeGender($payload['gender'])
                : $person->getAttribute('gender'),
            'bio' => array_key_exists('bio', $payload) ? $payload['bio'] : $person->getAttribute('bio'),
        ]);

        $dirty = $person->getDirty();
        $person->save();

        $this->syncPersonRelations->handle($person, $payload);
        $this->generatePersonSlugAction->syncPersonSlug($person);

        return $dirty;
    }

    private function normalizeGender(mixed $value): string
    {
        if ($value instanceof Gender) {
            return $value->value;
        }

        if (is_string($value) && Gender::tryFrom($value) instanceof Gender) {
            return $value;
        }

        return Gender::Male->value;
    }
}
