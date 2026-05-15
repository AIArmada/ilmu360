<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type AddressSelectionArray from AddressSelection
 * @phpstan-import-type CountryArray from Country
 *
 * @phpstan-type SpeakerMediaArray array{avatar_url: string, cover_url: ?string, share_image_url: ?string}
 * @phpstan-type SpeakerArray array{id: string, slug: string, name: string, gender: string|null, formatted_name: string, job_title: ?string, is_freelance: bool, bio: ?string, qualifications: list<string>, address: AddressSelectionArray|null, country: CountryArray|null, location: ?string, status: string, is_active: bool, is_following: bool, media: SpeakerMediaArray, gallery: list<array<string, mixed>>, institutions: list<array<string, mixed>>, contacts: list<array<string, mixed>>, social_media: list<array<string, mixed>>}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('Speaker')]
final readonly class Speaker implements Arrayable
{
    /**
     * @param  list<string>  $qualifications
     * @param  array{avatar_url: string, cover_url: ?string, share_image_url: ?string}  $media
     * @param  list<array<string, mixed>>  $gallery
     * @param  list<array<string, mixed>>  $institutions
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $social_media
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $gender,
        public string $formatted_name,
        public ?string $job_title,
        public bool $is_freelance,
        public ?string $bio,
        public array $qualifications,
        public ?AddressSelection $address,
        public ?Country $country,
        public ?string $location,
        public string $status,
        public bool $is_active,
        public bool $is_following,
        public array $media,
        public array $gallery,
        public array $institutions,
        public array $contacts,
        public array $social_media,
    ) {}

    /** @return SpeakerArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'gender' => $this->gender,
            'formatted_name' => $this->formatted_name,
            'job_title' => $this->job_title,
            'is_freelance' => $this->is_freelance,
            'bio' => $this->bio,
            'qualifications' => $this->qualifications,
            'address' => $this->address?->toArray(),
            'country' => $this->country?->toArray(),
            'location' => $this->location,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'is_following' => $this->is_following,
            'media' => $this->media,
            'gallery' => $this->gallery,
            'institutions' => $this->institutions,
            'contacts' => $this->contacts,
            'social_media' => $this->social_media,
        ];
    }
}
