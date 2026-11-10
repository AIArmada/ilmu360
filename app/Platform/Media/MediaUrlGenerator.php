<?php

namespace App\Platform\Media;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;
use Throwable;

class MediaUrlGenerator extends DefaultUrlGenerator
{
    #[\Override]
    public function getUrl(): string
    {
        if (! $this->shouldUseTemporaryUrl()) {
            return $this->resolveUrl();
        }

        $mediaKey = $this->media?->getKey();

        if ($mediaKey === null) {
            return $this->resolveUrl();
        }

        $lifetimeMinutes = max(1, (int) config('media-library.temporary_url_default_lifetime', 5));

        $cacheKey = sprintf(
            'media-library:url:%s:%s:%s:%s',
            $mediaKey,
            $this->conversion?->getName() ?? 'original',
            $this->getDiskName(),
            $this->media?->updated_at?->getTimestamp() ?? 0,
        );

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(max(1, $lifetimeMinutes - 1)),
            fn (): string => $this->resolveUrl(),
        );
    }

    private function resolveUrl(): string
    {
        if ($this->mediaFileIsMissing()) {
            return $this->resolveFallbackUrl();
        }

        if (! $this->shouldUseTemporaryUrl()) {
            $url = parent::getUrl();
        } else {
            try {
                $url = $this->getTemporaryUrl(
                    now()->addMinutes((int) config('media-library.temporary_url_default_lifetime', 5)),
                );
            } catch (Throwable) {
                $url = parent::getUrl();
            }
        }

        return $this->normalizeUrl($url);
    }

    private function normalizeUrl(string $url): string
    {
        if ($this->getDiskName() === 's3') {
            return $url;
        }

        $request = request();

        if (! $request instanceof Request) {
            return $url;
        }

        $parsed = parse_url($url);

        if (! isset($parsed['host'])) {
            return $url;
        }

        $currentHost = $request->getHttpHost();

        if ($parsed['host'] === $currentHost) {
            return $url;
        }

        $scheme = $request->getScheme();
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        return "{$scheme}://{$currentHost}{$path}{$query}";
    }

    protected function shouldUseTemporaryUrl(): bool
    {
        return $this->getDiskName() === 's3';
    }

    protected function mediaFileIsMissing(): bool
    {
        try {
            if ($this->getDiskName() !== 's3' || $this->media?->getKey() === null) {
                return ! $this->getDisk()->exists($this->getPathRelativeToRoot());
            }

            $cacheKey = sprintf(
                'media-library:exists:%s:%s:%s',
                $this->media->getKey(),
                $this->getPathRelativeToRoot(),
                $this->media->updated_at?->getTimestamp() ?? 0,
            );

            $cached = Cache::get($cacheKey);

            if ($cached === true) {
                return false;
            }

            if ($cached === false) {
                return true;
            }

            $exists = $this->getDisk()->exists($this->getPathRelativeToRoot());

            Cache::put(
                $cacheKey,
                $exists,
                $exists ? now()->addHours(6) : now()->addMinutes(5),
            );

            return ! $exists;
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
