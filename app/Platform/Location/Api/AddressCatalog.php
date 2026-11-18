<?php

declare(strict_types=1);

namespace App\Platform\Location\Api;

interface AddressCatalog
{
    /** @return list<array{id: string, label: string, iso2: string, key: ?string}> */
    public function countries(): array;

    /** @return list<array{id: string, label: string, code: string|null}> */
    public function states(?string $countryId): array;

    /** @return list<array{id: string, label: string}> */
    public function cities(?string $stateId, ?string $countryId = null): array;

    /** @return list<array{id: string, label: string, type: string, level: int|null}> */
    public function administrativeDistricts(?string $countryId, ?string $stateId = null): array;

    /** @return list<array{id: string, label: string, type: string, level: int|null}> */
    public function administrativeSubdivisions(?string $districtId, ?string $countryId = null, ?string $stateId = null): array;

    /** @return list<array{id: string, label: string, type: string, level: int|null}> */
    public function addressAreas(?string $countryId = null, ?string $parentId = null, ?int $level = null): array;
}
