<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Product address selection using the package's country-scoped address columns.
 *
 * @phpstan-type AddressSelectionArray array{country_id: ?string, state_id: ?string, city_id: ?string, admin_area_1_id: ?string, admin_area_2_id: ?string, admin_area_3_id: ?string, admin_area_4_id: ?string}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('AddressSelection')]
final readonly class AddressSelection implements Arrayable
{
    public function __construct(
        public ?string $country_id,
        public ?string $state_id,
        public ?string $city_id,
        public ?string $admin_area_1_id,
        public ?string $admin_area_2_id,
        public ?string $admin_area_3_id,
        public ?string $admin_area_4_id,
    ) {}

    /** @return AddressSelectionArray */
    public function toArray(): array
    {
        return [
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'admin_area_1_id' => $this->admin_area_1_id,
            'admin_area_2_id' => $this->admin_area_2_id,
            'admin_area_3_id' => $this->admin_area_3_id,
            'admin_area_4_id' => $this->admin_area_4_id,
        ];
    }
}
