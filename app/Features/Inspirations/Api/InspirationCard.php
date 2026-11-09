<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Api;

final readonly class InspirationCard
{
    public function __construct(
        public string $id,
        public string $locale,
        public string $title,
        public string $content,
        public string $contentHtml,
        public string $previewText,
        public ?string $source,
        public string $categoryValue,
        public string $categoryLabel,
        public string $categoryIcon,
        public string $categoryColor,
        public bool $isComic,
        public ?string $mediaThumbUrl,
        public ?string $mediaFullUrl,
    ) {}

    public function hasMedia(): bool
    {
        return $this->mediaThumbUrl !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'locale' => $this->locale,
            'title' => $this->title,
            'content' => $this->content,
            'content_html' => $this->contentHtml,
            'preview_text' => $this->previewText,
            'source' => $this->source,
            'category' => [
                'value' => $this->categoryValue,
                'label' => $this->categoryLabel,
                'icon' => $this->categoryIcon,
                'color' => $this->categoryColor,
                'is_comic' => $this->isComic,
            ],
            'media' => [
                'thumb_url' => $this->mediaThumbUrl,
                'full_url' => $this->mediaFullUrl,
                'has_media' => $this->hasMedia(),
            ],
        ];
    }
}
