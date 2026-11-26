<?php

declare(strict_types=1);

namespace App\Shared\Cache;

use AIArmada\Addressing\Features\Geography\Models\AddressArea;
use AIArmada\Addressing\Features\Geography\Models\AddressCountry;
use AIArmada\Events\Contracts\EventTaxonomyHierarchy;
use AIArmada\Events\Features\Taxonomy\Models\EventTaxonomy;
use AIArmada\Events\Features\Taxonomy\Models\EventTerm;
use AIArmada\Persons\Models\Title;
use App\Platform\Language\Models\Language;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SelectionCatalogCache implements SelectionCatalog
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    public function titleOptions(): array
    {
        return collect($this->titleRows())
            ->mapWithKeys(static fn (array $title): array => [$title['id'] => $title['name']])
            ->all();
    }

    public function titleLabels(array $values): array
    {
        $wanted = array_fill_keys(array_map(static fn (mixed $value): string => (string) $value, $values), true);

        return collect($this->titleRows())
            ->filter(static fn (array $title): bool => isset($wanted[$title['id']]))
            ->mapWithKeys(static fn (array $title): array => [$title['id'] => $title['name']])
            ->all();
    }

    public function titleSearchOptions(string $search): array
    {
        $search = mb_strtolower(trim($search));
        $titles = collect($this->titleRows());

        if ($search !== '') {
            $titles = $titles->filter(static fn (array $title): bool => str_contains(mb_strtolower($title['name']), $search)
                || str_contains(mb_strtolower($title['short_form'] ?? ''), $search)
            );
        }

        return $titles
            ->take(50)
            ->mapWithKeys(static fn (array $title): array => [$title['id'] => $title['name']])
            ->all();
    }

    public function languageOptions(
        string $key,
        array $preferredLabels = [],
        ?int $ttlSeconds = null,
    ): array {
        return collect($this->languageRows($ttlSeconds))
            ->mapWithKeys(static function (array $language) use ($key, $preferredLabels): array {
                $optionKey = match ($key) {
                    'code' => $language['code'],
                    'id' => $language['id'],
                    default => throw new InvalidArgumentException("Unsupported language option key [{$key}]."),
                };

                return [$optionKey => $preferredLabels[$language['code']] ?? $language['name']];
            })
            ->all();
    }

    public function languageCatalogOptions(): array
    {
        return collect($this->languageRows())
            ->map(static fn (array $language): array => [
                'id' => $language['id'],
                'label' => $language['name'],
            ])
            ->values()
            ->all();
    }

    public function countryOptions(): array
    {
        /** @var array<string, string> $options */
        $options = $this->rememberAddressOptions('countries', static fn (): array => AddressCountry::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all());

        return $options;
    }

    public function countryRows(): array
    {
        /** @var list<array{id: string, name: string, iso2: string}> $rows */
        $rows = $this->cache->remember(
            $this->versionKey('catalogs_geography', 'discovery:v:catalogs:geography'),
            'selection_catalog:address:country_rows',
            $this->catalogTtl(),
            static fn (): array => AddressCountry::query()
                ->orderBy('name')
                ->get(['id', 'name', 'iso2'])
                ->map(static fn (AddressCountry $country): array => [
                    'id' => (string) $country->getKey(),
                    'name' => (string) $country->name,
                    'iso2' => (string) $country->iso2,
                ])
                ->values()
                ->all(),
        );

        return $rows;
    }

    public function addressAreaNamesByIds(array $areaIds): array
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? trim($id) : '', $areaIds),
            static fn (string $id): bool => $id !== '' && Str::isUuid($id),
        )));

        if ($normalizedIds === []) {
            return [];
        }

        sort($normalizedIds);

        /** @var array<string, string> $names */
        $names = $this->cache->remember(
            $this->versionKey('catalogs_geography', 'discovery:v:catalogs:geography'),
            'selection_catalog:address:area_names:'.sha1(implode(',', $normalizedIds)),
            $this->catalogTtl(),
            static fn (): array => AddressArea::query()
                ->whereIn('id', $normalizedIds)
                ->pluck('name', 'id')
                ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
                ->all(),
        );

        return $names;
    }

    public function rememberAddressData(string $key, Closure $resolver): mixed
    {
        return $this->cache->remember(
            $this->versionKey('catalogs_geography', 'discovery:v:catalogs:geography'),
            "selection_catalog:address:data:{$key}",
            $this->catalogTtl(),
            $resolver,
        );
    }

    public function languageSelectOptions(): array
    {
        return collect($this->languageRows())
            ->mapWithKeys(static fn (array $language): array => [
                $language['code'] => self::languageDisplayLabel($language['name'], $language['native'] ?? null),
            ])
            ->all();
    }

    public function languageLabel(?string $code): ?string
    {
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return $this->languageLabelsForCodes([$code])[$code] ?? null;
    }

    public function languageLabelsForCodes(array $codes): array
    {
        $wanted = [];

        foreach ($codes as $code) {
            if (is_string($code) && trim($code) !== '') {
                $wanted[$code] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        return collect($this->languageRows())
            ->filter(static fn (array $language): bool => isset($wanted[$language['code']]))
            ->mapWithKeys(static fn (array $language): array => [
                $language['code'] => self::languageDisplayLabel($language['name'], $language['native'] ?? null),
            ])
            ->all();
    }

    public function languageOptionsForCodes(array $preferredCodes, array $preferredLabels = []): array
    {
        $order = array_flip($preferredCodes);

        return collect($this->languageRows())
            ->filter(static fn (array $language): bool => isset($order[$language['code']]))
            ->sortBy(static fn (array $language): int => $order[$language['code']])
            ->mapWithKeys(static function (array $language) use ($preferredLabels): array {
                $code = $language['code'];

                return [$language['id'] => $preferredLabels[$code] ?? $language['name']];
            })
            ->all();
    }

    public function rememberAddressOptions(string $key, Closure $resolver): array
    {
        /** @var array<int|string, string> $options */
        $options = $this->cache->remember(
            $this->versionKey('catalogs_geography', 'discovery:v:catalogs:geography'),
            "selection_catalog:address:{$key}",
            $this->catalogTtl(),
            $resolver,
        );

        return $options;
    }

    public function rememberAddressValue(string $key, Closure $resolver): ?string
    {
        /** @var string|null $value */
        $value = $this->cache->remember(
            $this->versionKey('catalogs_geography', 'discovery:v:catalogs:geography'),
            "selection_catalog:address:value:{$key}",
            $this->catalogTtl(),
            $resolver,
        );

        return $value;
    }

    public function eventCategoryOptions(): array
    {
        /** @var array<string, string> $options */
        $options = $this->cache->remember(
            $this->versionKey('catalogs_categories', 'discovery:v:catalogs:categories'),
            'selection_catalog:event_categories',
            $this->catalogTtl(),
            static fn (): array => app(EventTaxonomyHierarchy::class)->options('event_category'),
        );

        return $options;
    }

    public function taxonomyTermOptions(string $taxonomyCode): array
    {
        return collect($this->taxonomyTermRows($taxonomyCode))
            ->mapWithKeys(static fn (array $term): array => [$term['id'] => $term['name']])
            ->all();
    }

    public function taxonomyTermRows(string $taxonomyCode): array
    {
        $normalized = trim($taxonomyCode);

        if ($normalized === '') {
            return [];
        }

        /** @var list<array{id: string, name: string, code: string}> $rows */
        $rows = $this->cache->remember(
            $this->versionKey('catalogs_taxonomy_terms', 'discovery:v:catalogs:taxonomy_terms'),
            "selection_catalog:taxonomy_terms:rows:{$normalized}",
            $this->catalogTtl(),
            static function () use ($normalized): array {
                $taxonomyId = EventTaxonomy::query()
                    ->where('code', $normalized)
                    ->where('is_active', true)
                    ->value('id');

                if (! is_string($taxonomyId) || $taxonomyId === '') {
                    return [];
                }

                return EventTerm::query()
                    ->where('event_taxonomy_id', $taxonomyId)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code'])
                    ->map(static fn (EventTerm $term): array => [
                        'id' => (string) $term->getKey(),
                        'name' => (string) $term->name,
                        'code' => (string) $term->code,
                    ])
                    ->values()
                    ->all();
            },
        );

        return $rows;
    }

    public function geographyVersion(): string
    {
        return $this->cache->version($this->versionKey('catalogs_geography', 'discovery:v:catalogs:geography'));
    }

    public function languagesVersion(): string
    {
        return $this->cache->version($this->versionKey('catalogs_languages', 'discovery:v:catalogs:languages'));
    }

    public function donationBanksVersion(): string
    {
        return $this->cache->version($this->versionKey('catalogs_banks', 'discovery:v:catalogs:banks'));
    }

    public function taxonomyTermsVersion(): string
    {
        return $this->cache->version($this->versionKey('catalogs_taxonomy_terms', 'discovery:v:catalogs:taxonomy_terms'));
    }

    public function bustTitles(): void
    {
        $this->cache->bump($this->versionKey('catalogs_titles', 'discovery:v:catalogs:titles'));
    }

    public function bustLanguages(): void
    {
        $this->cache->bump($this->versionKey('catalogs_languages', 'discovery:v:catalogs:languages'));
    }

    public function bustAddress(): void
    {
        $this->cache->bump($this->versionKey('catalogs_geography', 'discovery:v:catalogs:geography'));
    }

    public function bustEventCategories(): void
    {
        $this->cache->bump($this->versionKey('catalogs_categories', 'discovery:v:catalogs:categories'));
    }

    public function bustTaxonomyTerms(): void
    {
        $this->cache->bump($this->versionKey('catalogs_taxonomy_terms', 'discovery:v:catalogs:taxonomy_terms'));
    }

    public function bustDonationBanks(): void
    {
        $this->cache->bump($this->versionKey('catalogs_banks', 'discovery:v:catalogs:banks'));
    }

    public function bustAll(): void
    {
        $this->bustTitles();
        $this->bustLanguages();
        $this->bustAddress();
        $this->bustEventCategories();
        $this->bustTaxonomyTerms();
        $this->bustDonationBanks();
    }

    private static function languageDisplayLabel(string $name, ?string $native): string
    {
        $native = trim((string) $native);

        if ($native === '' || mb_strtolower($native) === mb_strtolower($name)) {
            return $name;
        }

        return "{$name} ({$native})";
    }

    /**
     * @return list<array{id: string, name: string, short_form: string|null}>
     */
    private function titleRows(): array
    {
        /** @var list<array{id: string, name: string, short_form: string|null}> $rows */
        $rows = $this->cache->remember(
            $this->versionKey('catalogs_titles', 'discovery:v:catalogs:titles'),
            'selection_catalog:titles:rows',
            $this->catalogTtl(),
            static fn (): array => Title::query()
                ->with('category')
                ->get()
                ->sortBy(static fn (Title $title): array => [
                    $title->category->sort_order,
                    $title->sort_order,
                    $title->name,
                ])
                ->map(static fn (Title $title): array => [
                    'id' => (string) $title->getKey(),
                    'name' => (string) $title->name,
                    'short_form' => $title->short_form !== null ? (string) $title->short_form : null,
                ])
                ->values()
                ->all(),
        );

        return $rows;
    }

    /**
     * @return list<array{id: string, code: string, name: string, native: string|null}>
     */
    private function languageRows(?int $ttlSeconds = null): array
    {
        $defaultTtl = $this->catalogTtl();
        $ttl = $ttlSeconds ?? $defaultTtl;
        $cacheKey = $ttl === $defaultTtl
            ? 'selection_catalog:languages:rows'
            : "selection_catalog:languages:rows:ttl:{$ttl}";

        /** @var list<array{id: string, code: string, name: string, native: string|null}> $rows */
        $rows = $this->cache->remember(
            $this->versionKey('catalogs_languages', 'discovery:v:catalogs:languages'),
            $cacheKey,
            $ttl,
            static fn (): array => Language::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'native'])
                ->map(static fn (Language $language): array => [
                    'id' => (string) $language->getKey(),
                    'code' => (string) $language->code,
                    'name' => (string) $language->name,
                    'native' => $language->native !== null ? (string) $language->native : null,
                ])
                ->values()
                ->all(),
        );

        return $rows;
    }

    private function catalogTtl(): int
    {
        return (int) config('discovery.cache.ttls.catalog', 3600);
    }

    private function versionKey(string $name, string $default): string
    {
        $configured = config("discovery.cache.versions.{$name}", $default);

        return is_string($configured) && $configured !== '' ? $configured : $default;
    }
}
