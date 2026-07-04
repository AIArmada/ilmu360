<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-type AddressSelectionArray array{country_id: ?string, state_id: ?string, district_id: ?string, subdistrict_id: ?string}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('AddressSelection')]
final readonly class AddressSelection implements Arrayable
{
    public function __construct(
        public ?string $country_id,
        public ?string $state_id,
        public ?string $district_id,
        public ?string $subdistrict_id,
    ) {}

    /** @return AddressSelectionArray */
    public function toArray(): array
    {
        return [
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'district_id' => $this->district_id,
            'subdistrict_id' => $this->subdistrict_id,
        ];
    }
}
