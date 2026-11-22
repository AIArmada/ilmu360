<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Queries;

use AIArmada\CommerceSupport\Support\VersionedCache;
use App\Features\Inspirations\Api\InspirationCard;
use App\Features\Inspirations\Api\InspirationReader;
use App\Features\Inspirations\Enums\InspirationCategory;
use App\Features\Inspirations\Models\Inspiration;

final readonly class DatabaseInspirationReader implements InspirationReader
{
    public function __construct(private VersionedCache $cache) {}

    public function random(?string $locale = null): ?InspirationCard
    {
        $localeKey = trim((string) ($locale ?? app()->getLocale()));
        $localeKey = $localeKey !== '' ? $localeKey : 'default';

        /** @var list<string> $ids */
        $ids = $this->cache->remember(
            Inspiration::ACTIVE_CACHE_VERSION_KEY,
            "inspirations:active:ids:{$localeKey}",
            300,
            static fn (): array => Inspiration::query()
                ->active()
                ->forLocale($locale)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all(),
        );

        if ($ids === []) {
            return null;
        }

        $selectedId = $ids[array_rand($ids)];
        $inspiration = Inspiration::query()
            ->with('media')
            ->find($selectedId);

        if (! $inspiration instanceof Inspiration) {
            $inspiration = Inspiration::query()
                ->with('media')
                ->active()
                ->forLocale($locale)
                ->first();
        }

        return $inspiration instanceof Inspiration ? $this->card($inspiration) : null;
    }

    private function card(Inspiration $inspiration): InspirationCard
    {
        $category = $inspiration->category;

        if (! $category instanceof InspirationCategory) {
            $category = InspirationCategory::from((string) $category);
        }

        $contentHtml = $inspiration->renderContentHtml();
        $thumbUrl = $inspiration->getFirstMediaUrl('main', 'thumb') ?: null;
        $fullUrl = $inspiration->getFirstMediaUrl('main') ?: null;

        return new InspirationCard(
            id: (string) $inspiration->getKey(),
            locale: (string) $inspiration->locale,
            title: (string) $inspiration->title,
            content: trim(strip_tags($contentHtml)),
            contentHtml: $contentHtml,
            previewText: $inspiration->contentPreviewText(160),
            source: is_string($inspiration->source) ? $inspiration->source : null,
            categoryValue: $category->value,
            categoryLabel: $category->label(),
            categoryIcon: $category->icon(),
            categoryColor: $category->color(),
            isComic: $category === InspirationCategory::IslamicComic,
            mediaThumbUrl: $thumbUrl,
            mediaFullUrl: $fullUrl,
        );
    }
}
