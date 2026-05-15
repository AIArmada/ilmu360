<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type SpeakerListItemArray from SpeakerListItem
 *
 * @phpstan-type SpeakerDirectoryResponseArray array{data: list<SpeakerListItemArray>, meta: array{pagination: array{page: int, per_page: int, total: int}, following: array{total: int}, cache: array{version: string}, request_id: string}}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('SpeakerDirectoryResponse')]
final readonly class SpeakerDirectoryResponse implements Arrayable
{
    /**
     * @param  list<SpeakerListItem>  $data
     * @param  array{pagination: array{page: int, per_page: int, total: int}, following: array{total: int}, cache: array{version: string}, request_id: string}  $meta
     */
    public function __construct(
        public array $data,
        public array $meta,
    ) {}

    /** @return SpeakerDirectoryResponseArray */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (SpeakerListItem $item): array => $item->toArray(), $this->data),
            'meta' => $this->meta,
        ];
    }
}
