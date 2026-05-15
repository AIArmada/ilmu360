<?php

declare(strict_types=1);

namespace App\Support\Signals;

use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Support\Str;

final class ProductSignalsSurfaceResolver
{
    /**
     * @return array{enabled?: mixed, slug?: mixed}
     */
    private function panelConfig(string $surface): array
    {
        $panelConfig = config("product-signals.panels.{$surface}");

        return is_array($panelConfig) ? $panelConfig : [];
    }

    public function surfaceEnabled(string $surface): bool
    {
        $enabled = $this->panelConfig($surface)['enabled'] ?? null;

        if (is_bool($enabled)) {
            return $enabled;
        }

        return $surface === 'public' || str_contains($surface, '-') || ctype_alnum(str_replace('_', '', $surface));
    }

    public function slugForSurface(string $surface): ?string
    {
        $configuredSlug = $this->panelConfig($surface)['slug'] ?? null;

        if (is_string($configuredSlug) && trim($configuredSlug) !== '') {
            return trim($configuredSlug);
        }

        $baseSlug = Str::slug((string) config('app.name', 'application')) ?: 'application';

        if ($surface === 'public') {
            return $baseSlug;
        }

        $normalizedSurface = Str::slug($surface);

        return $normalizedSurface === '' ? null : $baseSlug.'-'.$normalizedSurface;
    }

    public function domainForSurface(string $surface): ?string
    {
        if ($surface !== 'public') {
            $configuredPanelDomain = config("filament-panels.domains.{$surface}");

            if (is_string($configuredPanelDomain) && trim($configuredPanelDomain) !== '') {
                return trim($configuredPanelDomain);
            }
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $host;
    }

    public function nameForSurface(string $surface): string
    {
        $appName = (string) config('app.name', 'Application');

        return $surface === 'public'
            ? $appName.' Website'
            : $appName.' '.Str::of($surface)->replace(['-', '_'], ' ')->headline();
    }

    public function resolveOrCreateTrackedProperty(string $surface): ?TrackedProperty
    {
        if (! $this->surfaceEnabled($surface)) {
            return null;
        }

        $slug = $this->slugForSurface($surface);

        if ($slug === null) {
            return null;
        }

        $properties = TrackedProperty::query()
            ->withoutOwnerScope()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('type', (string) config('signals.defaults.property_type', 'website'))
            ->where('slug', $slug)
            ->limit(2)
            ->get();

        if ($properties->count() > 1) {
            logger()->warning('Signals tracked property resolution found duplicate surfaces.', [
                'surface' => $surface,
                'slug' => $slug,
                'matches' => $properties->count(),
            ]);

            return null;
        }

        $trackedProperty = $properties->first() ?? new TrackedProperty;

        $trackedProperty->fill([
            'name' => $this->nameForSurface($surface),
            'slug' => $slug,
            'domain' => $this->domainForSurface($surface),
            'type' => (string) config('signals.defaults.property_type', 'website'),
            'timezone' => (string) config('signals.defaults.timezone', config('app.timezone', 'UTC')),
            'currency' => (string) config('signals.defaults.currency', 'MYR'),
            'is_active' => true,
            'settings' => null,
        ]);

        $trackedProperty->save();

        return $trackedProperty->fresh();
    }
}
