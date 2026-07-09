<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Product address selection: country + admin_area_1 (state/region) + admin_area_2 (district/area).
 *
 * @phpstan-type AddressSelectionArray array{country_id: ?string, admin_area_1_id: ?string, admin_area_2_id: ?string}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('AddressSelection')]
final readonly class AddressSelection implements Arrayable
{
    public function __construct(
        public ?string $country_id,
        public ?string $admin_area_1_id,
        public ?string $admin_area_2_id,
    ) {}

    /** @return AddressSelectionArray */
    public function toArray(): array
    {
        return [
            'country_id' => $this->country_id,
            'admin_area_1_id' => $this->admin_area_1_id,
            'admin_area_2_id' => $this->admin_area_2_id,
        ];
    }
}
