<?php

declare(strict_types=1);

namespace App\Actions\Contributions;

use Illuminate\Contracts\Support\Arrayable;
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

            if ($this->valuesEqual($value, $originalData[$key])) {
                continue;
            }

            $changes[$key] = $value;
        }

        return $changes;
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        return json_encode($this->normalizeComparable($left)) === json_encode($this->normalizeComparable($right));
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
