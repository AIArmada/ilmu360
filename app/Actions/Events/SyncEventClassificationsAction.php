<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Actions\SyncEventClassificationsAction as PackageSyncEventClassificationsAction;
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
    ) {}

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
        $types = [TagType::Domain, TagType::Source, TagType::Discipline, TagType::Issue];

        return $this->synchronizer->handle(
            event: $event,
            taxonomyValues: [
                TagType::Domain->value => $validated['domain_tags'] ?? [],
                TagType::Source->value => $validated['source_tags'] ?? [],
                TagType::Discipline->value => $validated['discipline_tags'] ?? [],
                TagType::Issue->value => $validated['issue_tags'] ?? [],
            ],
            taxonomyDefinitions: collect($types)->mapWithKeys(fn (TagType $type): array => [
                $type->value => [
                    'name' => $type->label(),
                    'description' => $type->description(),
                    'is_hierarchical' => false,
                    'is_active' => true,
                ],
            ])->all(),
            explicitTermIds: $validated['taxonomy_term_ids'] ?? [],
        );
    }
}
