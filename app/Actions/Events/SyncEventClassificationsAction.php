<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Actions\SyncEventClassificationsAction as PackageSyncEventClassificationsAction;
use App\Contracts\EventCategoryCatalog;
use App\Enums\TagType;
use App\Models\Event;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * ilmu360 taxonomy vocabulary adapter for the generic package synchronizer.
 */
class SyncEventClassificationsAction
{
    use AsAction;

    public function __construct(
        private readonly PackageSyncEventClassificationsAction $synchronizer,
        private readonly EventCategoryCatalog $categoryCatalog,
    ) {}

    /**
     * @param  array{
     *     event_category_ids?: list<mixed>,
     *     domain_tags?: list<mixed>,
     *     source_tags?: list<mixed>,
     *     discipline_tags?: list<mixed>,
     *     issue_tags?: list<mixed>,
     *     taxonomy_term_ids?: list<mixed>
     * }  $validated
     */
    public function handle(Event $event, array $validated): int
    {
        $types = [TagType::Domain, TagType::Source, TagType::Discipline, TagType::Issue];
        $existing = $event->classifications()
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->get(['event_taxonomy_id', 'taxonomy_code', 'event_term_id']);
        $existingByTaxonomy = $existing->groupBy(fn ($classification): string => (string) $classification->taxonomy_code);
        $categoryValues = array_key_exists('event_category_ids', $validated)
            ? $this->categoryCatalog->validateTermIds(is_array($validated['event_category_ids']) ? $validated['event_category_ids'] : [])
            : $existingByTaxonomy->get(EventCategoryCatalog::TAXONOMY_CODE, collect())->pluck('event_term_id')->map(strval(...))->all();

        return $this->synchronizer->handle(
            event: $event,
            taxonomyValues: [
                EventCategoryCatalog::TAXONOMY_CODE => $categoryValues,
                TagType::Domain->value => $this->valuesFor($validated, TagType::Domain->value, $existingByTaxonomy),
                TagType::Source->value => $this->valuesFor($validated, TagType::Source->value, $existingByTaxonomy),
                TagType::Discipline->value => $this->valuesFor($validated, TagType::Discipline->value, $existingByTaxonomy),
                TagType::Issue->value => $this->valuesFor($validated, TagType::Issue->value, $existingByTaxonomy),
            ],
            taxonomyDefinitions: collect($types)->mapWithKeys(fn (TagType $type): array => [
                $type->value => [
                    'name' => $type->label(),
                    'description' => $type->description(),
                    'is_hierarchical' => false,
                    'is_active' => true,
                ],
            ])->merge([
                EventCategoryCatalog::TAXONOMY_CODE => [
                    'name' => 'Event Category',
                    'description' => 'Hierarchical categories for events.',
                    'is_hierarchical' => true,
                    'is_active' => true,
                ],
            ])->all(),
            explicitTermIds: collect($validated['taxonomy_term_ids'] ?? [])
                ->merge($existing->filter(fn ($classification): bool => ! in_array($classification->taxonomy_code, [
                    EventCategoryCatalog::TAXONOMY_CODE,
                    TagType::Domain->value,
                    TagType::Source->value,
                    TagType::Discipline->value,
                    TagType::Issue->value,
                ], true))->pluck('event_term_id'))
                ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int, mixed>
     */
    private function valuesFor(array $validated, string $taxonomyCode, mixed $existingByTaxonomy): array
    {
        $key = match ($taxonomyCode) {
            TagType::Domain->value => 'domain_tags',
            TagType::Source->value => 'source_tags',
            TagType::Discipline->value => 'discipline_tags',
            default => 'issue_tags',
        };

        if (array_key_exists($key, $validated)) {
            return is_array($validated[$key]) ? $validated[$key] : [];
        }

        return $existingByTaxonomy instanceof \Illuminate\Support\Collection
            ? $existingByTaxonomy->get($taxonomyCode, collect())->pluck('event_term_id')->all()
            : [];
    }
}
