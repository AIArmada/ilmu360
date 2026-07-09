<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Models\EventClassification;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\TagType;
use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Package-native event classification writer (ADR-011).
 *
 * Accepts ilmu360 form fields that historically used Spatie tag ids/names and
 * persists only EventTaxonomy / EventTerm / EventClassification rows.
 */
class SyncEventClassificationsAction
{
    use AsAction;

    /**
     * @param  array{
     *     domain_tags?: list<mixed>,
     *     source_tags?: list<mixed>,
     *     discipline_tags?: list<mixed>,
     *     issue_tags?: list<mixed>,
     *     taxonomy_term_ids?: list<mixed>
     * }  $validated
     */
    public function handle(Event $event, array $validated): int
    {
        $termIds = collect();

        foreach ([
            'domain_tags' => TagType::Domain,
            'source_tags' => TagType::Source,
            'discipline_tags' => TagType::Discipline,
            'issue_tags' => TagType::Issue,
        ] as $field => $taxonomyType) {
            $termIds = $termIds->merge(
                $this->resolveTermIdsForField($taxonomyType, $validated[$field] ?? []),
            );
        }

        $termIds = $termIds
            ->merge($this->resolveExplicitTermIds($validated['taxonomy_term_ids'] ?? []))
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->unique()
            ->values();

        EventClassification::query()
            ->where('event_id', $event->getKey())
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->delete();

        if ($termIds->isEmpty()) {
            return 0;
        }

        $terms = EventTerm::query()
            ->whereIn('id', $termIds->all())
            ->get()
            ->keyBy(fn (EventTerm $term): string => (string) $term->getKey());

        $synced = 0;
        $sort = 0;

        foreach ($termIds as $termId) {
            $term = $terms->get((string) $termId);

            if (! $term instanceof EventTerm) {
                continue;
            }

            $taxonomy = EventTaxonomy::query()->find($term->event_taxonomy_id);

            EventClassification::query()->create([
                'event_id' => $event->getKey(),
                'event_taxonomy_id' => $term->event_taxonomy_id,
                'event_term_id' => $term->getKey(),
                'taxonomy_code' => $taxonomy?->code,
                'term_code' => $term->code,
                'is_primary' => $sort === 0,
                'weight' => $term->sort_order ?? $sort,
                'sort_order' => $sort,
            ]);

            $sort++;
            $synced++;
        }

        return $synced;
    }

    /**
     * @param  list<mixed>  $values
     * @return Collection<int, string>
     */
    private function resolveTermIdsForField(TagType $taxonomyType, array $values): Collection
    {
        $ids = collect();

        foreach ($values as $value) {
            if (is_string($value) && Str::isUuid($value)) {
                $termId = $this->resolveUuidToTermId($taxonomyType, $value);

                if ($termId !== null) {
                    $ids->push($termId);
                }

                continue;
            }

            $name = is_string($value) ? trim($value) : '';

            if ($name === '') {
                continue;
            }

            $ids->push($this->firstOrCreateTerm($taxonomyType, Str::slug($name), $name)->getKey());
        }

        return $ids;
    }

    /**
     * @param  list<mixed>  $values
     * @return Collection<int, string>
     */
    private function resolveExplicitTermIds(array $values): Collection
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && Str::isUuid($value))
            ->values();
    }

    private function resolveUuidToTermId(TagType $taxonomyType, string $uuid): ?string
    {
        $term = EventTerm::query()->find($uuid);

        if (! $term instanceof EventTerm) {
            return null;
        }

        return (string) $term->getKey();
    }

    private function firstOrCreateTerm(
        TagType $taxonomyType,
        string $code,
        string $name,
        bool $is_active = true,
        int $sortOrder = 0,
    ): EventTerm {
        $taxonomy = EventTaxonomy::query()->firstOrCreate(
            ['code' => $taxonomyType->value],
            [
                'name' => $taxonomyType->label(),
                'description' => $taxonomyType->description(),
                'is_hierarchical' => false,
                'is_active' => true,
            ],
        );

        return EventTerm::query()->firstOrCreate(
            [
                'event_taxonomy_id' => $taxonomy->getKey(),
                'code' => $code,
            ],
            [
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_active' => $is_active,
            ],
        );
    }
}
