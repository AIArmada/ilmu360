<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type CountryArray from Country
 *
 * @phpstan-type PersonListItemArray array{id: string, slug: string, name: string, middle_name: string|null, family_name: string|null, gender: string|null, formatted_name: string, status: string, verified_by: ?string, events_count: int, avatar_url: string, country: CountryArray|null, is_following: bool}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('PersonListItem')]
final readonly class PersonListItem implements Arrayable
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $middle_name,
        public ?string $family_name,
        public ?string $gender,
        public string $formatted_name,
        public string $status,
        public ?string $verified_by,
        public int $events_count,
        public string $avatar_url,
        public ?Country $country,
        public bool $is_following,
    ) {}

    /** @return PersonListItemArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'middle_name' => $this->middle_name,
            'family_name' => $this->family_name,
            'gender' => $this->gender,
            'formatted_name' => $this->formatted_name,
            'status' => $this->status,
            'verified_by' => $this->verified_by,
            'events_count' => $this->events_count,
            'avatar_url' => $this->avatar_url,
            'country' => $this->country?->toArray(),
            'is_following' => $this->is_following,
        ];
    }
}
