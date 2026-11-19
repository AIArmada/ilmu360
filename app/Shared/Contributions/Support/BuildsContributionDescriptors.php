<?php

declare(strict_types=1);

namespace App\Shared\Contributions\Support;

use BackedEnum;

trait BuildsContributionDescriptors
{
    /**
     * @param  list<string|int>|null  $allowedValues
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function field(
        string $name,
        string $type,
        ?int $maxLength = null,
        ?array $allowedValues = null,
        ?string $catalog = null,
        array $meta = [],
    ): array {
        return array_merge(
            array_filter([
                'name' => $name,
                'type' => $type,
                'required' => false,
                'max_length' => $maxLength,
                'allowed_values' => $allowedValues,
                'catalog' => $catalog,
            ], static fn (mixed $value): bool => $value !== null),
            array_filter($meta, static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @return list<string|int>
     */
    private function enumValues(string $enumClass): array
    {
        return array_map(
            static fn (BackedEnum $case): string|int => $case->value,
            $enumClass::cases(),
        );
    }
}
