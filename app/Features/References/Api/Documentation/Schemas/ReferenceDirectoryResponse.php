<?php

declare(strict_types=1);

namespace App\Features\References\Api\Documentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;

/**
 * @phpstan-import-type ReferenceListItemArray from ReferenceListItem
 *
 * @phpstan-type ReferenceDirectoryResponseArray array{data: list<ReferenceListItemArray>, meta: array{pagination: array{page: int, per_page: int, total: int}, following: array{total: int}}}
 */
#[SchemaName('ReferenceDirectoryResponse')]
final readonly class ReferenceDirectoryResponse
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
