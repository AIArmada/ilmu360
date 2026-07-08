<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Models\EventClassification;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\TagType;
use App\Models\Event;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncEventTaxonomiesAction
{
    use AsAction;

    private const TYPE_MAP = [
        'domain' => 'domain',
        'discipline' => 'discipline',
        'source' => 'source',
        'issue' => 'issue',
    ];

    public function handle(Event $event): int
    {
        $tags = $event->tags;

        if ($tags->isEmpty()) {
            return 0;
        }

        $synced = 0;

        foreach ($tags as $tag) {
            $taxonomyType = self::TYPE_MAP[$tag->type] ?? null;

            if ($taxonomyType === null) {
                continue;
            }

            $tagName = $tag->name;
            $tagSlug = $tag->slug;

            if (! is_string($tagName) || ! is_string($tagSlug)) {
                continue;
            }

            $taxonomy = EventTaxonomy::firstOrCreate(
                ['code' => $taxonomyType],
                [
                    'name' => TagType::from($taxonomyType)->label(),
                    'description' => TagType::from($taxonomyType)->description(),
                    'is_hierarchical' => false,
                    'is_active' => true,
                ],
            );

            $term = EventTerm::firstOrCreate(
                [
                    'event_taxonomy_id' => $taxonomy->getKey(),
                    'code' => $tagSlug,
                ],
                [
                    'name' => $tagName,
                    'sort_order' => $tag->order_column ?? 0,
                    'is_active' => $tag->status === 'verified',
                ],
            );

            EventClassification::firstOrCreate(
                [
                    'event_id' => $event->getKey(),
                    'event_term_id' => $term->getKey(),
                ],
                [
                    'event_taxonomy_id' => $taxonomy->getKey(),
                    'taxonomy_code' => $taxonomyType,
                    'term_code' => $tagSlug,
                    'is_primary' => false,
                    'weight' => $tag->order_column ?? 0,
                    'sort_order' => $tag->order_column ?? 0,
                ],
            );

            $synced++;
        }

        return $synced;
    }
}
