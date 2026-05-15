<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-type AddressSelectionArray array{country_id: ?int, state_id: ?int, district_id: ?int, subdistrict_id: ?int}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('AddressSelection')]
final readonly class AddressSelection implements Arrayable
{
    public function __construct(
        public ?int $country_id,
        public ?int $state_id,
        public ?int $district_id,
        public ?int $subdistrict_id,
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
