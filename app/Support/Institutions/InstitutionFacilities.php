<?php

namespace App\Support\Institutions;

use InvalidArgumentException;

/**
 * Validated own-facilities flag map for an institution.
 *
 * Stored as a JSON object of facility code => bool. Allowed codes mirror the
 * shared FacilityType catalog seeded by FacilityTypeSeeder.
 */
final class InstitutionFacilities
{
    /**
     * @var array<string, string> code => label
     */
    public const array OPTIONS = [
        'parking' => 'Parking',
        'oku' => 'OKU Access',
        'women_section' => 'Women Section',
        'ablution_area' => 'Ablution Area',
        'air_conditioning' => 'Air Conditioning',
        'wheelchair_access' => 'Wheelchair Access',
    ];

    /**
     * @param  array<string, bool>  $flags
     */
    private function __construct(
        private readonly array $flags,
    ) {}

    /**
     * @param  array<string, bool>  $flags
     */
    public static function fromFlags(array $flags): self
    {
        $errors = self::validate($flags);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        return new self($flags);
    }

    /**
     * Normalize database/form input into a validated flag map.
     *
     * Accepts a code => bool map, a flat list of enabled codes, or null.
     * List items are validated before array_fill_keys so malformed entries
     * raise an exception instead of a PHP warning or TypeError.
     *
     * @return array<string, bool>|null
     */
    public static function normalize(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value->flags();
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Institution facilities must be an array or null.');
        }

        if ($value === []) {
            return [];
        }

        $errors = self::validate($value);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $flags = array_is_list($value)
            ? array_fill_keys($value, true)
            : $value;

        $normalized = [];

        foreach ($flags as $code => $enabled) {
            $normalized[(string) $code] = $enabled;
        }

        return self::fromFlags($normalized)->flags();
    }

    /**
     * @return array<string, string> field key => message
     */
    public static function validate(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof self) {
            return [];
        }

        if (! is_array($value)) {
            return ['facilities' => __('Institution facilities must be an array.')];
        }

        if (array_is_list($value)) {
            return self::validateList($value);
        }

        $errors = [];

        foreach ($value as $code => $enabled) {
            $code = (string) $code;

            if (! array_key_exists($code, self::OPTIONS)) {
                $errors["facilities.{$code}"] = __('The facility [:code] is invalid.', ['code' => $code]);

                continue;
            }

            if (! is_bool($enabled)) {
                $errors["facilities.{$code}"] = __('The facility [:code] must be true or false.', ['code' => $code]);
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $value
     * @return array<string, string> field key => message
     */
    private static function validateList(array $value): array
    {
        $errors = [];

        foreach (array_values($value) as $index => $item) {
            if (! is_string($item)) {
                $errors["facilities.{$index}"] = __('The facility at position [:index] is invalid.', ['index' => (string) $index]);

                continue;
            }

            if (! array_key_exists($item, self::OPTIONS)) {
                $errors["facilities.{$index}"] = __('The facility [:code] is invalid.', ['code' => $item]);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::OPTIONS);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::OPTIONS;
    }

    /**
     * @return array<string, bool>
     */
    public function flags(): array
    {
        return $this->flags;
    }

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        return array_keys(array_filter($this->flags));
    }
}
