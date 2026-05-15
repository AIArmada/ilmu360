<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type CountryArray from Country
 *
 * @phpstan-type InstitutionListItemArray array{id: string, slug: string, name: string, type: string|null, nickname: ?string, display_name: string, events_count: int, public_image_url: string, logo_url: string, cover_url: ?string, country: CountryArray|null, location: ?string, distance_km: ?float, is_following: bool}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('InstitutionListItem')]
final readonly class InstitutionListItem implements Arrayable
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $type,
        public ?string $nickname,
        public string $display_name,
        public int $events_count,
        public string $public_image_url,
        public string $logo_url,
        public ?string $cover_url,
        public ?Country $country,
        public ?string $location,
        public ?float $distance_km,
        public bool $is_following,
    ) {}

    /** @return InstitutionListItemArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type,
            'nickname' => $this->nickname,
            'display_name' => $this->display_name,
            'events_count' => $this->events_count,
            'public_image_url' => $this->public_image_url,
            'logo_url' => $this->logo_url,
            'cover_url' => $this->cover_url,
            'country' => $this->country?->toArray(),
            'location' => $this->location,
            'distance_km' => $this->distance_km,
            'is_following' => $this->is_following,
        ];
    }
}
