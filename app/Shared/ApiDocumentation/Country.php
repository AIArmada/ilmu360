<?php

declare(strict_types=1);

namespace App\Shared\ApiDocumentation;

use Dedoc\Scramble\Attributes\SchemaName;

/**
 * @phpstan-type CountryArray array{id: string, name: string, iso2: string, key: ?string}
 */
#[SchemaName('Country')]
final readonly class Country
{
    public function __construct(
        public string $id,
        public string $name,
        public string $iso2,
        public ?string $key,
    ) {}

    /** @return CountryArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iso2' => $this->iso2,
            'key' => $this->key,
        ];
    }
}
