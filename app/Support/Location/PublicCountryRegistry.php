<?php

namespace App\Support\Location;

use App\Models\Country;

class PublicCountryRegistry
{
    /**
     * @var array<string, int|null>
     */
    protected ?array $countryIdsByIso2 = null;

    /**
     * @param  int|string|null  $countryId
     */
    public function resolveCountryId(
        mixed $countryId = null,
        mixed $countryCode = null,
        mixed $countryKey = null,
        bool $enabledOnly = false,
    ): ?int {
        $resolvedCountryId = $this->normalizeCountryIdInput($countryId);

        if ($resolvedCountryId === null) {
            $normalizedCountryCode = $this->normalizeLookupString($countryCode);

            if ($normalizedCountryCode !== null) {
                $resolvedCountryId = $this->countryIdFromIso2($normalizedCountryCode);
            }
        }

        if ($resolvedCountryId === null) {
            $normalizedCountryKey = is_string($countryKey)
                ? strtolower(trim($countryKey))
                : null;

            if ($normalizedCountryKey !== null && $normalizedCountryKey !== '' && $this->has($normalizedCountryKey)) {
                if ($enabledOnly && ! $this->isEnabled($normalizedCountryKey)) {
                    return null;
                }

                $resolvedCountryId = $this->countryIdForKey($normalizedCountryKey);
            }
        }

        if (! is_int($resolvedCountryId)) {
            return null;
        }

        if ($enabledOnly) {
            return $this->normalizeCountryId($resolvedCountryId);
        }

        return Country::query()->whereKey($resolvedCountryId)->exists()
            ? $resolvedCountryId
            : null;
    }

    /**
     * @return array{id: int, name: string, iso2: string, key: ?string}|null
     */
    public function countryDataForId(?int $countryId): ?array
    {
        if (! is_int($countryId)) {
            return null;
        }

        $country = Country::query()
            ->whereKey($countryId)
            ->first(['id', 'name', 'iso2']);

        if (! $country instanceof Country) {
            return null;
        }

        return [
            'id' => (int) $country->id,
            'name' => (string) $country->name,
            'iso2' => strtoupper((string) $country->iso2),
            'key' => $this->keyForCountryId((int) $country->id),
        ];
    }

    /**
     * @return array<string, array{label: string, flag: string, iso2: string, default_timezone: string, timezones?: array<int, string>, enabled: bool, coming_soon: bool}>
     */
    public function all(): array
    {
        /** @var array<string, array{label: string, flag: string, iso2: string, default_timezone: string, timezones?: array<int, string>, enabled: bool, coming_soon: bool}> $countries */
        $countries = config('public-countries.countries', []);

        return $countries;
    }

    public function defaultKey(): string
    {
        $configured = (string) config('public-countries.default', 'malaysia');

        if ($this->has($configured) && $this->isEnabled($configured)) {
            return $configured;
        }

        if ($this->has('malaysia') && $this->isEnabled('malaysia')) {
            return 'malaysia';
        }

        foreach ($this->all() as $key => $country) {
            if ((bool) ($country['enabled'] ?? false)) {
                return $key;
            }
        }

        return array_key_first($this->all()) ?? 'malaysia';
    }

    /**
     * @return array{key: string, label: string, flag: string, iso2: string, default_timezone: string, timezones: array<int, string>, enabled: bool, coming_soon: bool}
     */
    public function default(): array
    {
        return $this->country($this->defaultKey());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function isEnabled(string $key): bool
    {
        return (bool) ($this->all()[$key]['enabled'] ?? false);
    }

    /**
     * @return array{key: string, label: string, flag: string, iso2: string, default_timezone: string, timezones: array<int, string>, enabled: bool, coming_soon: bool}|null
     */
    public function find(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        return $this->country($key);
    }

    /**
     * @return array{key: string, label: string, flag: string, iso2: string, default_timezone: string, timezones: array<int, string>, enabled: bool, coming_soon: bool}
     */
    public function country(string $key): array
    {
        $country = $this->all()[$key];
        $timezones = $country['timezones'] ?? [$country['default_timezone']];

        if (! is_array($timezones) || $timezones === []) {
            $timezones = [$country['default_timezone']];
        }

        return [
            'key' => $key,
            'label' => $country['label'],
            'flag' => $country['flag'],
            'iso2' => strtoupper($country['iso2']),
            'default_timezone' => $country['default_timezone'],
            'timezones' => array_values(array_filter(
                $timezones,
                static fn (string $timezone): bool => $timezone !== '',
            )),
            'enabled' => (bool) $country['enabled'],
            'coming_soon' => (bool) $country['coming_soon'],
        ];
    }

    public function normalizeCountryKey(?string $key, bool $enabledOnly = true): string
    {
        $normalized = strtolower(trim((string) $key));

        if ($normalized === '' || ! $this->has($normalized)) {
            return $this->defaultKey();
        }

        if ($enabledOnly && ! $this->isEnabled($normalized)) {
            return $this->defaultKey();
        }

        return $normalized;
    }

    public function countryIdForKey(string $key): ?int
    {
        $country = $this->find($key);

        if ($country === null) {
            return null;
        }

        return $this->countryIdFromIso2($country['iso2']);
    }

    public function normalizeCountryId(?int $countryId): ?int
    {
        if ($countryId === null) {
            return null;
        }

        $countryKey = $this->keyForCountryId($countryId);

        if ($countryKey === null || ! $this->isEnabled($countryKey)) {
            return null;
        }

        return $countryId;
    }

    public function keyForCountryId(int $countryId): ?string
    {
        foreach ($this->all() as $key => $country) {
            $resolvedCountryId = $this->countryIdFromIso2((string) $country['iso2']);

            if ($resolvedCountryId === $countryId) {
                return $key;
            }
        }

        return null;
    }

    public function countryIdFromIso2(string $iso2): ?int
    {
        $normalizedIso2 = strtoupper(trim($iso2));

        if ($normalizedIso2 === '') {
            return null;
        }

        return $this->countryIdsByIso2()[$normalizedIso2] ?? null;
    }

    public function defaultTimezoneForKey(string $key): string
    {
        return $this->country($this->normalizeCountryKey($key, enabledOnly: false))['default_timezone'];
    }

    public function defaultTimezoneForCountryId(?int $countryId): string
    {
        $countryKey = is_int($countryId) ? $this->keyForCountryId($countryId) : null;

        if ($countryKey === null) {
            return $this->default()['default_timezone'];
        }

        return $this->defaultTimezoneForKey($countryKey);
    }

    /**
     * @return array<int, string>
     */
    public function timezonesForKey(string $key): array
    {
        return $this->country($this->normalizeCountryKey($key, enabledOnly: false))['timezones'];
    }

    public function singleTimezoneForKey(string $key): ?string
    {
        $timezones = $this->timezonesForKey($key);

        if (count($timezones) !== 1) {
            return null;
        }

        return $timezones[0];
    }

    public function singleTimezoneForCountryId(?int $countryId): ?string
    {
        $countryKey = is_int($countryId) ? $this->keyForCountryId($countryId) : null;

        if ($countryKey === null) {
            return $this->singleTimezoneForKey($this->defaultKey());
        }

        return $this->singleTimezoneForKey($countryKey);
    }

    /**
     * @return array<string, int|null>
     */
    protected function countryIdsByIso2(): array
    {
        if (is_array($this->countryIdsByIso2)) {
            return $this->countryIdsByIso2;
        }

        $this->countryIdsByIso2 = Country::query()
            ->whereNotNull('iso2')
            ->get(['id', 'iso2'])
            ->mapWithKeys(static fn (Country $country): array => [
                strtoupper((string) $country->iso2) => (int) $country->id,
            ])
            ->all();

        return $this->countryIdsByIso2;
    }

    private function normalizeLookupString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = strtoupper(trim($value));

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    private function normalizeCountryIdInput(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        if ($normalizedValue === '' || preg_match('/^\d+$/', $normalizedValue) !== 1) {
            return null;
        }

        return (int) $normalizedValue;
    }
}
