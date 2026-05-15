<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-type CountryArray array{id: int, name: string, iso2: string, key: ?string}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('Country')]
final readonly class Country implements Arrayable
{
    public function __construct(
        public int $id,
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
