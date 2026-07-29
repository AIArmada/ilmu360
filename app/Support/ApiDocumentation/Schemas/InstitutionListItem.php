<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type CountryArray from Country
 *
 * @phpstan-type InstitutionNameArray array{name_type: string, full_name: string, language_code: string, is_primary: bool}
 * @phpstan-type InstitutionListItemArray array{id: string, slug: string, name: string, type: string|null, names: list<InstitutionNameArray>, display_name: string, events_count: int, public_image_url: string, logo_url: string, cover_url: ?string, country: CountryArray|null, location: ?string, distance_km: ?float, is_following: bool, verified_by: ?string}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('InstitutionListItem')]
final readonly class InstitutionListItem implements Arrayable
{
    /**
     * @param  list<InstitutionNameArray>  $names
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $type,
        public array $names,
        public string $display_name,
        public int $events_count,
        public string $public_image_url,
        public string $logo_url,
        public ?string $cover_url,
        public ?Country $country,
        public ?string $location,
        public ?float $distance_km,
        public bool $is_following,
        public ?string $verified_by,
    ) {}

    /** @return InstitutionListItemArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type,
            'names' => $this->names,
            'display_name' => $this->display_name,
            'events_count' => $this->events_count,
            'public_image_url' => $this->public_image_url,
            'logo_url' => $this->logo_url,
            'cover_url' => $this->cover_url,
            'country' => $this->country?->toArray(),
            'location' => $this->location,
            'distance_km' => $this->distance_km,
            'is_following' => $this->is_following,
            'verified_by' => $this->verified_by,
        ];
    }
}
