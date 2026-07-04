<?php

namespace Database\Seeders\Concerns;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait SeedsPackageAddresses
{
    protected function malaysiaCountry(): ?AddressCountry
    {
        return AddressCountry::query()->where('iso2', 'MY')->first();
    }

    protected function malaysiaAreaByName(string $name, int $level, ?string $parentId = null): ?AddressArea
    {
        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return null;
        }

        return AddressArea::query()
            ->where('country_code', 'MY')
            ->where('level', $level)
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('name')
            ->get()
            ->first(function (AddressArea $area) use ($needle): bool {
                $haystack = Str::lower($area->name);

                return Str::contains($haystack, $needle) || Str::contains($needle, $haystack);
            });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function seedPrimaryPackageAddress(Model $model, array $attributes): Address
    {
        $attributes = $this->normalizePackageAddressAttributes($attributes);
        $address = method_exists($model, 'primaryAddress') ? $model->primaryAddress() : null;

        if ($address instanceof Address) {
            $address->fill($attributes);
            $address->save();

            return $address;
        }

        $address = Address::query()->create($attributes);

        if (method_exists($model, 'attachAddress')) {
            $model->attachAddress($address, 'primary', true);
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizePackageAddressAttributes(array $attributes): array
    {
        $country = isset($attributes['country_id']) && is_string($attributes['country_id'])
            ? AddressCountry::query()->find($attributes['country_id'])
            : null;

        $adminArea1 = isset($attributes['admin_area_1_id']) && is_string($attributes['admin_area_1_id'])
            ? AddressArea::query()->find($attributes['admin_area_1_id'])
            : null;

        $adminArea2 = isset($attributes['admin_area_2_id']) && is_string($attributes['admin_area_2_id'])
            ? AddressArea::query()->find($attributes['admin_area_2_id'])
            : null;

        $attributes['country'] ??= $country?->name;
        $attributes['country_code'] ??= $country?->iso2;
        $attributes['state'] ??= $adminArea1?->name;
        $attributes['city'] ??= $adminArea2 !== null
            ? $adminArea2->name
            : $adminArea1?->name;

        $attributes['formatted_address'] ??= collect([
            $attributes['line1'] ?? null,
            $attributes['line2'] ?? null,
            $attributes['postcode'] ?? null,
            $attributes['city'] ?? null,
            $attributes['state'] ?? null,
            $attributes['country'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->implode(', ');

        return $attributes;
    }
}
