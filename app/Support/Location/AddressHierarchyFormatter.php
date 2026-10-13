<?php

declare(strict_types=1);

namespace App\Support\Location;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Support\AddressFormatRenderer;
use App\Forms\SharedFormSchema;
use InvalidArgumentException;

final class AddressHierarchyFormatter
{
    /**
     * Short location parts behind cards, lists, and API payloads.
     * Delegates to the package renderer (address-formats.json summary
     * spec or its fallback): full address minus street lines, postcode,
     * and country. A listing row must never 500 on one unusable
     * address, so unknown definitions degrade to the country fallback;
     * write-time validation still fails loudly in the package.
     *
     * @return list<string>
     */
    public static function parts(?Address $address): array
    {
        $parts = self::packageParts($address);

        if ($parts !== []) {
            return $parts;
        }

        $countryName = self::countryName($address);

        return $countryName === null ? [] : [$countryName];
    }

    /**
     * Last two summary parts: the short "town, state" subtitle.
     *
     * @return list<string>
     */
    public static function shortParts(?Address $address): array
    {
        return array_slice(self::parts($address), -2);
    }

    /** @return array{street: ?string, locality: ?string, regional: ?string} */
    public static function displayLines(?Address $address): array
    {
        $empty = ['street' => null, 'locality' => null, 'regional' => null];

        if (! $address instanceof Address) {
            return $empty;
        }

        $render = AddressPackageData::for($address);

        // The street lines are entered text, not format rules: they stay
        // visible even when no country definition can render the rest.
        if ($render === null) {
            return ['street' => self::streetLine($address), 'locality' => null, 'regional' => null];
        }

        $data = $render['data'];
        $streetValues = array_values(array_filter(
            [$data->line1, $data->line2, $data->line3],
            static fn (?string $line): bool => $line !== null && $line !== '',
        ));

        try {
            $block = AddressFormatRenderer::format($render['code'], $render['data']);
        } catch (InvalidArgumentException) {
            return [
                'street' => $streetValues === [] ? null : implode(', ', $streetValues),
                'locality' => null,
                'regional' => null,
            ];
        }

        $nonStreet = array_values(array_filter(
            explode("\n", $block),
            static fn (string $line): bool => ! in_array($line, $streetValues, true),
        ));

        try {
            $parts = AddressFormatRenderer::summaryParts($render['code'], $render['data']);
        } catch (InvalidArgumentException) {
            $parts = [];
        }

        $locality = null;

        if ($data->postcode !== null && $data->postcode !== '') {
            foreach ($nonStreet as $line) {
                if (str_contains($line, $data->postcode)) {
                    $locality = $line;

                    break;
                }
            }
        }

        // Without a postcode there is no postcode line: lead with the
        // finest summary part so the mukim never vanishes from the page.
        $locality ??= $parts[0] ?? $nonStreet[0] ?? null;

        $regional = count($parts) > 1 ? implode(', ', array_slice($parts, 1)) : null;

        return [
            'street' => $streetValues === [] ? null : implode(', ', $streetValues),
            'locality' => $locality,
            'regional' => $regional,
        ];
    }

    public static function format(?Address $address, string $separator = ', '): string
    {
        return implode($separator, self::parts($address));
    }

    public static function formatShort(?Address $address, string $separator = ', '): string
    {
        return implode($separator, self::shortParts($address));
    }

    /**
     * Full package block as one line: field order and content come from
     * the country's format definition; only the line separator is
     * presentation. Unknown definitions render empty (callers own the
     * fallback), same never-500 contract as parts() and displayLines().
     */
    public static function formatBlock(?Address $address, string $separator = ', '): string
    {
        $render = AddressPackageData::for($address);

        if ($render === null) {
            return '';
        }

        try {
            $block = AddressFormatRenderer::format($render['code'], $render['data']);
        } catch (InvalidArgumentException) {
            return '';
        }

        return str_replace("\n", $separator, $block);
    }

    /** @return list<string> */
    private static function packageParts(?Address $address): array
    {
        $render = AddressPackageData::for($address);

        if ($render === null) {
            return [];
        }

        try {
            return AddressFormatRenderer::summaryParts($render['code'], $render['data']);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    public static function roleAreaName(?Address $address, string $role): ?string
    {
        if (! $address instanceof Address) {
            return null;
        }

        return $address->areaAssignments()
            ->with('area')
            ->get()
            ->firstWhere('role', $role)?->area?->name;
    }

    public static function subdivisionOrLocalityName(?Address $address): ?string
    {
        return self::roleAreaName($address, AddressAssignments::ADMINISTRATIVE_SUBDIVISION)
            ?? self::roleAreaName($address, AddressAssignments::POSTAL_LOCALITY);
    }

    public static function roleAreaLabel(?Address $address, string $role, string $fallback): string
    {
        if (! $address instanceof Address) {
            return $fallback;
        }

        return SharedFormSchema::locationLevelLabel(
            $address->getAttribute('country_id') ?? $address->getAttribute('country_code'),
            $role,
            $fallback,
            $address->getAttribute('state_id'),
            AddressAssignments::forAddress($address),
        );
    }

    public static function subdivisionOrLocalityLabel(?Address $address, string $fallback = 'Mukim / Kawasan'): string
    {
        if (self::roleAreaName($address, AddressAssignments::ADMINISTRATIVE_SUBDIVISION) !== null) {
            return self::roleAreaLabel($address, AddressAssignments::ADMINISTRATIVE_SUBDIVISION, $fallback);
        }

        return self::roleAreaLabel($address, AddressAssignments::POSTAL_LOCALITY, $fallback);
    }

    private static function streetLine(Address $address): ?string
    {
        $attributes = $address->getAttributes();
        $lines = [];

        foreach (['line1', 'line2', 'line3'] as $key) {
            $line = self::clean($attributes[$key] ?? null);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines === [] ? null : implode(', ', $lines);
    }

    private static function countryName(?Address $address): ?string
    {
        if (! $address instanceof Address) {
            return null;
        }

        $attributes = $address->getAttributes();
        $name = self::clean($attributes['country'] ?? null);

        if ($name !== null) {
            return $name;
        }

        if ($address->relationLoaded('country')) {
            $name = self::clean($address->getRelation('country')?->getAttribute('name'));

            if ($name !== null) {
                return $name;
            }
        }

        $countryId = $attributes['country_id'] ?? null;

        if (is_int($countryId)) {
            $countryId = (string) $countryId;
        }

        if (! is_string($countryId) || trim($countryId) === '') {
            return null;
        }

        $country = AddressCountry::query()->find($countryId);

        return $country instanceof AddressCountry ? self::clean($country->getAttribute('name')) : null;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
