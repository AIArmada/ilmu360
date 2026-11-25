<?php

declare(strict_types=1);

namespace App\Shared\Cache;

use Closure;

interface SelectionCatalog
{
    /**
     * @return array<string, string>
     */
    public function titleOptions(): array;

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    public function titleLabels(array $values): array;

    /**
     * @return array<string, string>
     */
    public function titleSearchOptions(string $search): array;

    /**
     * @param  array<string, string>  $preferredLabels
     * @return array<string, string>
     */
    public function languageOptions(
        string $key,
        array $preferredLabels = [],
        ?int $ttlSeconds = null,
    ): array;

    /**
     * @return list<array{id: string, label: string}>
     */
    public function languageCatalogOptions(): array;

    /**
     * @return array<string, string>
     */
    public function languageSelectOptions(): array;

    public function languageLabel(?string $code): ?string;

    /**
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    public function languageLabelsForCodes(array $codes): array;

    /**
     * @param  list<string>  $preferredCodes
     * @param  array<string, string>  $preferredLabels
     * @return array<string, string>
     */
    public function languageOptionsForCodes(array $preferredCodes, array $preferredLabels = []): array;

    /**
     * @return array<string, string>
     */
    public function countryOptions(): array;

    /**
     * @return list<array{id: string, name: string, iso2: string}>
     */
    public function countryRows(): array;

    /**
     * @param  list<string>  $areaIds
     * @return array<string, string>
     */
    public function addressAreaNamesByIds(array $areaIds): array;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $resolver
     * @return TValue
     */
    public function rememberAddressData(string $key, Closure $resolver): mixed;

    /**
     * @param  Closure(): array<int|string, string>  $resolver
     * @return array<int|string, string>
     */
    public function rememberAddressOptions(string $key, Closure $resolver): array;

    /**
     * @param  Closure(): ?string  $resolver
     */
    public function rememberAddressValue(string $key, Closure $resolver): ?string;

    /**
     * @return array<string, string>
     */
    public function eventCategoryOptions(): array;

    /**
     * @return array<string, string>
     */
    public function taxonomyTermOptions(string $taxonomyCode): array;

    /**
     * @return list<array{id: string, name: string, code: string}>
     */
    public function taxonomyTermRows(string $taxonomyCode): array;

    public function geographyVersion(): string;

    public function languagesVersion(): string;

    public function donationBanksVersion(): string;

    public function taxonomyTermsVersion(): string;

    public function bustTitles(): void;

    public function bustLanguages(): void;

    public function bustAddress(): void;

    public function bustEventCategories(): void;

    public function bustTaxonomyTerms(): void;

    public function bustDonationBanks(): void;

    public function bustAll(): void;
}
