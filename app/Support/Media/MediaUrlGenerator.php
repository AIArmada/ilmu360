<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;
use Throwable;

class MediaUrlGenerator extends DefaultUrlGenerator
{
    #[\Override]
    public function getUrl(): string
    {
        if ($this->mediaFileIsMissing()) {
            return $this->resolveFallbackUrl();
        }

        if (! $this->shouldUseTemporaryUrl()) {
            return parent::getUrl();
        }

        try {
            return $this->getTemporaryUrl(
                now()->addMinutes((int) config('media-library.temporary_url_default_lifetime', 5)),
            );
        } catch (Throwable) {
            return parent::getUrl();
        }
    }

    protected function shouldUseTemporaryUrl(): bool
    {
        return $this->getDiskName() === 's3';
    }

    protected function mediaFileIsMissing(): bool
    {
        try {
            return ! $this->getDisk()->exists($this->getPathRelativeToRoot());
        } catch (Throwable) {
            return false;
        }
    }

    protected function resolveFallbackUrl(): string
    {
        $model = $this->media?->model;

        if (! is_object($model) || ! method_exists($model, 'getFallbackMediaUrl')) {
            return '';
        }

        $collectionName = (string) $this->media->collection_name;
        $conversionName = $this->conversion?->getName() ?? '';
        $fallbackUrl = $model->getFallbackMediaUrl($collectionName, $conversionName);

        if (is_string($fallbackUrl) && $fallbackUrl !== '') {
            return $fallbackUrl;
        }

        $defaultFallbackUrl = $model->getFallbackMediaUrl($collectionName);

        return is_string($defaultFallbackUrl) ? $defaultFallbackUrl : '';
    }
}
