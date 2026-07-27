<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Product address selection using country-profile-defined address assignments.
 *
 * @phpstan-type AddressSelectionArray array{country_id: ?string, state_id: ?string, city_id: ?string, area_assignments: array<string, string>}
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
        /** @var array<string, string> */
        public array $area_assignments = [],
    ) {}

    /** @return AddressSelectionArray */
    public function toArray(): array
    {
        return [
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'area_assignments' => $this->area_assignments,
        ];
    }
}
