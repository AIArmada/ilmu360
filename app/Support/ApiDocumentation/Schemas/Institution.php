<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type AddressSelectionArray from AddressSelection
 * @phpstan-import-type CountryArray from Country
 *
 * @phpstan-type InstitutionNameArray array{name_type: string, full_name: string, language_code: string, is_primary: bool}
 * @phpstan-type InstitutionMediaArray array{public_image_url: string, logo_url: string, cover_url: ?string}
 * @phpstan-type InstitutionArray array{id: string, slug: string, name: string, names: list<InstitutionNameArray>, display_name: string, description: ?string, status: string, verified_by: ?string, type: string|null, type_label: ?string, address_line: ?string, address: AddressSelectionArray|null, country: CountryArray|null, map_url: ?string, followers_count: int, person_count: int, is_following: bool, media: InstitutionMediaArray, contacts: list<array<string, mixed>>, social_media: list<array<string, mixed>>, waze_url: ?string, donation_channels: list<array<string, mixed>>}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('Institution')]
final readonly class Institution implements Arrayable
{
    /**
     * @param  array{public_image_url: string, logo_url: string, cover_url: ?string}  $media
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $social_media
     * @param  list<array<string, mixed>>  $donation_channels
     * @param  list<InstitutionNameArray>  $names
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public array $names,
        public string $display_name,
        public ?string $description,
        public string $status,
        public ?string $verified_by,
        public ?string $type,
        public ?string $type_label,
        public ?string $address_line,
        public ?AddressSelection $address,
        public ?Country $country,
        public ?string $map_url,
        public int $followers_count,
        public int $person_count,
        public bool $is_following,
        public array $media,
        public array $contacts,
        public array $social_media,
        public ?string $waze_url,
        public array $donation_channels,
    ) {}

    /** @return InstitutionArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'names' => $this->names,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'status' => $this->status,
            'verified_by' => $this->verified_by,
            'type' => $this->type,
            'type_label' => $this->type_label,
            'address_line' => $this->address_line,
            'address' => $this->address?->toArray(),
            'country' => $this->country?->toArray(),
            'map_url' => $this->map_url,
            'followers_count' => $this->followers_count,
            'person_count' => $this->person_count,
            'is_following' => $this->is_following,
            'media' => $this->media,
            'contacts' => $this->contacts,
            'social_media' => $this->social_media,
            'waze_url' => $this->waze_url,
            'donation_channels' => $this->donation_channels,
        ];
    }
}
