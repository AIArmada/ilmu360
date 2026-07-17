<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Contracts\EventCategoryCatalog;
use App\Models\Event;

final readonly class EventCategoryPresenter
{
    public function __construct(private EventCategoryCatalog $catalog) {}

    /** @return list<array{id: string, code: string, name: string, path: string, is_primary: bool}> */
    public function forEvent(Event $event): array
    {
        $event->loadMissing(['classifications.term']);
        $paths = $this->paths($this->catalog->tree());

        return $event->classifications
            ->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE)
            ->sortBy('sort_order')
            ->map(function ($classification) use ($paths): ?array {
                $term = $classification->term;
                if ($term === null) {
                    return null;
                }
                $id = (string) $term->getKey();

                return [
                    'id' => $id,
                    'code' => (string) $term->code,
                    'name' => (string) $term->name,
                    'path' => $paths[$id] ?? (string) $term->name,
                    'is_primary' => (bool) $classification->is_primary,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, string>
     */
    private function paths(array $nodes): array
    {
        $paths = [];
        foreach ($nodes as $node) {
            if (isset($node['id'], $node['path'])) {
                $paths[(string) $node['id']] = (string) $node['path'];
            }
            if (is_array($node['children'] ?? null)) {
                $paths += $this->paths($node['children']);
            }
        }

        return $paths;
    }
}
