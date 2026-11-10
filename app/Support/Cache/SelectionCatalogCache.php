<?php

declare(strict_types=1);

namespace App\Support\Cache;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Events\Contracts\EventTaxonomyHierarchy;
use AIArmada\Persons\Models\Title;
use App\Models\Language;
use App\Platform\Cache\CacheVersionStore;
use App\Platform\Cache\VersionedCache;
use Closure;

final class SelectionCatalogCache
{
    private const int CATALOG_TTL_SECONDS = 86400;

    private const string TITLES_VERSION_KEY = 'selection_catalog:titles:version:v1';

    private const string LANGUAGES_VERSION_KEY = 'selection_catalog:languages:version:v2';

    private const string ADDRESS_VERSION_KEY = 'selection_catalog:address:version:v1';

    private const string EVENT_CATEGORIES_VERSION_KEY = 'selection_catalog:event_categories:version:v1';

    public function __construct(
        private readonly EventTaxonomyHierarchy $eventTaxonomyHierarchy,
        private readonly CacheVersionStore $cacheVersions,
        private readonly VersionedCache $versionedCache,
    ) {}

    /**
     * @return array<string, string>
     */
    public function titleOptions(): array
    {
        return collect($this->titleRows())
            ->mapWithKeys(static fn (array $title): array => [$title['id'] => $title['name']])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    public function titleLabels(array $values): array
    {
        $wanted = array_fill_keys(array_map(static fn (mixed $value): string => (string) $value, $values), true);

        return collect($this->titleRows())
            ->filter(static fn (array $title): bool => isset($wanted[$title['id']]))
            ->mapWithKeys(static fn (array $title): array => [$title['id'] => $title['name']])
            ->all();
    }

    /**
     * @return array<string, string>
     */
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

    /**
     * @param  array<string, string>  $preferredLabels  Optional localized labels keyed by language code.
     * @return array<string, string>
     */
    public function languageOptions(string $key, array $preferredLabels = []): array
    {
        return collect($this->languageRows())
            ->mapWithKeys(static function (array $language) use ($key, $preferredLabels): array {
                $optionKey = match ($key) {
                    'code' => $language['code'],
                    'id' => $language['id'],
                    default => throw new \InvalidArgumentException("Unsupported language option key [{$key}]."),
                };

                return [$optionKey => $preferredLabels[$language['code']] ?? $language['name']];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function countryOptions(): array
    {
        /** @var array<string, string> $options */
        $options = $this->rememberAddressOptions('countries', static fn (): array => AddressCountry::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all());

        return $options;
    }

    /**
     * Full language catalog keyed by code with friendly native-inclusive labels.
     *
     * @return array<string, string>
     */
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

    /**
     * @param  list<string>  $codes
     * @return array<string, string>
     */
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

    private static function languageDisplayLabel(string $name, ?string $native): string
    {
        $native = trim((string) $native);

        if ($native === '' || mb_strtolower($native) === mb_strtolower($name)) {
            return $name;
        }

        return "{$name} ({$native})";
    }

    /**
     * @param  list<string>  $preferredCodes
     * @param  array<string, string>  $preferredLabels
     * @return array<string, string>
     */
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

    public function bustTitles(): void
    {
        $this->cacheVersions->bump(self::TITLES_VERSION_KEY);
    }

    public function bustLanguages(): void
    {
        $this->cacheVersions->bump(self::LANGUAGES_VERSION_KEY);
    }

    /**
     * @param  Closure(): array<int|string, string>  $resolver
     * @return array<int|string, string>
     */
    public function rememberAddressOptions(string $key, Closure $resolver): array
    {
        /** @var array<int|string, string> $options */
        $options = $this->versionedCache->remember(
            self::ADDRESS_VERSION_KEY,
            "selection_catalog:address:{$key}",
            self::CATALOG_TTL_SECONDS,
            $resolver,
        );

        return $options;
    }

    /** @param Closure(): ?string $resolver */
    public function rememberAddressValue(string $key, Closure $resolver): ?string
    {
        /** @var string|null $value */
        $value = $this->versionedCache->remember(
            self::ADDRESS_VERSION_KEY,
            "selection_catalog:address:value:{$key}",
            self::CATALOG_TTL_SECONDS,
            $resolver,
        );

        return $value;
    }

    /** @return array<string, string> */
    public function eventCategoryOptions(): array
    {
        /** @var array<string, string> $options */
        $options = $this->versionedCache->remember(
            self::EVENT_CATEGORIES_VERSION_KEY,
            'selection_catalog:event_categories',
            self::CATALOG_TTL_SECONDS,
            fn (): array => $this->eventTaxonomyHierarchy->options('event_category'),
        );

        return $options;
    }

    public function bustAddress(): void
    {
        $this->cacheVersions->bump(self::ADDRESS_VERSION_KEY);
    }

    public function bustEventCategories(): void
    {
        $this->cacheVersions->bump(self::EVENT_CATEGORIES_VERSION_KEY);
    }

    public function bustAll(): void
    {
        $this->bustTitles();
        $this->bustLanguages();
        $this->bustAddress();
        $this->bustEventCategories();
    }

    /**
     * @return list<array{id: string, name: string, short_form: string|null}>
     */
    private function titleRows(): array
    {
        /** @var list<array{id: string, name: string, short_form: string|null}> $rows */
        $rows = $this->versionedCache->remember(
            self::TITLES_VERSION_KEY,
            'selection_catalog:titles:rows',
            self::CATALOG_TTL_SECONDS,
            static fn (): array => Title::query()
                ->with('category')
                ->get()
                ->sortBy(fn (Title $title): array => [
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
    private function languageRows(): array
    {
        /** @var list<array{id: string, code: string, name: string, native: string|null}> $rows */
        $rows = $this->versionedCache->remember(
            self::LANGUAGES_VERSION_KEY,
            'selection_catalog:languages:rows',
            self::CATALOG_TTL_SECONDS,
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
}
