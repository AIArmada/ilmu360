<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type CountryArray from Country
 *
 * @phpstan-type SpeakerListItemArray array{id: string, slug: string, name: string, gender: string|null, formatted_name: string, status: string, is_active: bool, events_count: int, avatar_url: string, country: CountryArray|null, is_following: bool}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('SpeakerListItem')]
final readonly class SpeakerListItem implements Arrayable
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $gender,
        public string $formatted_name,
        public string $status,
        public bool $is_active,
        public int $events_count,
        public string $avatar_url,
        public ?Country $country,
        public bool $is_following,
    ) {}

    /** @return SpeakerListItemArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'gender' => $this->gender,
            'formatted_name' => $this->formatted_name,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'events_count' => $this->events_count,
            'avatar_url' => $this->avatar_url,
            'country' => $this->country?->toArray(),
            'is_following' => $this->is_following,
        ];
    }
}
