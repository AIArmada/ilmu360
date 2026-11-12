<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Shared\Taxonomy\EventTaxonomyCode;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolve or create the package taxonomy/term pair used by the contribution
 * form's create-option flow. Pending tags are created active with the same
 * defaults as the previous inline form write.
 */
final readonly class EnsureEventTaxonomyTermAction
{
    public function handle(string $eventTaxonomyCode, string $name): string
    {
        $name = trim($name);
        $code = Str::slug($name);

        if ($name === '' || $code === '') {
            return '';
        }

        $type = EventTaxonomyCode::tryFrom($eventTaxonomyCode);

        if (! $type instanceof EventTaxonomyCode) {
            throw new InvalidArgumentException("Unsupported event taxonomy code [{$eventTaxonomyCode}].");
        }

        $taxonomy = EventTaxonomy::query()->firstOrCreate(
            ['code' => $type->value],
            [
                'name' => $type->label(),
                'description' => $type->description(),
                'is_hierarchical' => false,
                'is_active' => true,
            ],
        );

        $term = EventTerm::query()->firstOrCreate(
            [
                'event_taxonomy_id' => $taxonomy->getKey(),
                'code' => $code,
            ],
            [
                'name' => $name,
                'sort_order' => 0,
                'is_active' => true,
            ],
        );

        return (string) $term->getKey();
    }
}
