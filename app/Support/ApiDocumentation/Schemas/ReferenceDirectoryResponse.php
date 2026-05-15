<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type ReferenceListItemArray from ReferenceListItem
 *
 * @phpstan-type ReferenceDirectoryResponseArray array{data: list<ReferenceListItemArray>, meta: array{pagination: array{page: int, per_page: int, total: int}, following: array{total: int}}}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('ReferenceDirectoryResponse')]
final readonly class ReferenceDirectoryResponse implements Arrayable
{
    /**
     * @param  list<ReferenceListItem>  $data
     * @param  array{pagination: array{page: int, per_page: int, total: int}, following: array{total: int}}  $meta
     */
    public function __construct(
        public array $data,
        public array $meta,
    ) {}

    /** @return ReferenceDirectoryResponseArray */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (ReferenceListItem $item): array => $item->toArray(), $this->data),
            'meta' => $this->meta,
        ];
    }
}
