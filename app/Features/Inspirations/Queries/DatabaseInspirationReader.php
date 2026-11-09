<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Queries;

use App\Features\Inspirations\Api\InspirationCard;
use App\Features\Inspirations\Api\InspirationReader;
use App\Features\Inspirations\Enums\InspirationCategory;
use App\Features\Inspirations\Models\Inspiration;

final class DatabaseInspirationReader implements InspirationReader
{
    public function random(?string $locale = null): ?InspirationCard
    {
        $inspiration = Inspiration::query()
            ->with('media')
            ->active()
            ->forLocale($locale)
            ->inRandomOrder()
            ->first();

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
