<?php

declare(strict_types=1);

namespace App\Features\Contributions\Actions;

use AIArmada\Addressing\Features\AddressLifecycle\Support\AddressAssignments;
use App\Shared\Normalization\SubmittedFieldNormalizer;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveContributionChangedPayloadAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $originalData
     * @return array<string, mixed>
     */
    public function handle(array $state, array $originalData): array
    {
        $changes = [];

        foreach ($state as $key => $value) {
            if (! array_key_exists($key, $originalData)) {
                continue;
            }

            if ($this->valuesEqual($key, $value, $originalData[$key])) {
                continue;
            }

            $changes[$key] = $value;
        }

        return $changes;
    }

    private function valuesEqual(string $key, mixed $submitted, mixed $original): bool
    {
        if (is_array($submitted) && is_array($original)) {
            if ($key === 'address' && $submitted !== []) {
                $submitted = Arr::except($submitted, [
                    'google_maps_normalization_enabled',
                    'google_maps_remote_lookup_enabled',
                ]);
                $original = array_intersect_key($original, $submitted);
            }

            if ($key === 'contactMethods') {
                $submitted = SubmittedFieldNormalizer::normalizeContactRowsForComparison($submitted);
                $original = SubmittedFieldNormalizer::normalizeContactRowsForComparison($original);
            }

            if ($key === 'donation_channels') {
                $submitted = SubmittedFieldNormalizer::normalizeDonationChannelRowsForComparison($submitted);
                $original = SubmittedFieldNormalizer::normalizeDonationChannelRowsForComparison($original);

                foreach ($original as $index => $row) {
                    if (($submitted[$index]['bank_name'] ?? null) === null
                        && $row['bank_code'] !== null
                        && ($submitted[$index]['bank_code'] ?? null) === $row['bank_code']) {
                        $original[$index]['bank_name'] = null;
                    }
                }
            }
        }

        return json_encode($this->normalizeComparable($submitted)) === json_encode($this->normalizeComparable($original));
    }

    private function normalizeComparable(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Arrayable) {
            return $this->normalizeComparable($value->toArray());
        }

        if ($this->isComparableIntegerIdentifier($key, $value)) {
            return (int) $value;
        }

        if (is_array($value)) {
            if ($key === 'area_assignments') {
                $value = AddressAssignments::normalize($value);
            }

            $normalized = [];

            foreach ($value as $arrayKey => $item) {
                $normalized[$arrayKey] = $this->normalizeComparable($item, is_string($arrayKey) ? $arrayKey : null);
            }

            if (! array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        return $value;
    }

    private function isComparableIntegerIdentifier(?string $key, mixed $value): bool
    {
        return is_string($key)
            && str_ends_with($key, '_id')
            && is_string($value)
            && preg_match('/^[1-9]\d*$/', $value) === 1;
    }
}
