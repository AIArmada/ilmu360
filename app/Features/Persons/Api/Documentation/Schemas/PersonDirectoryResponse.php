<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Documentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;

/**
 * @phpstan-import-type PersonListItemArray from PersonListItem
 *
 * @phpstan-type PersonDirectoryResponseArray array{data: list<PersonListItemArray>, meta: array{pagination: array{page: int, per_page: int, total: int}, following: array{total: int}, cache: array{version: string}, request_id: string}}
 */
#[SchemaName('PersonDirectoryResponse')]
final readonly class PersonDirectoryResponse
{
    /**
     * @param  list<PersonListItem>  $data
     * @param  array{pagination: array{page: int, per_page: int, total: int}, following: array{total: int}, cache: array{version: string}, request_id: string}  $meta
     */
    public function __construct(
        public array $data,
        public array $meta,
    ) {}

    /** @return PersonDirectoryResponseArray */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (PersonListItem $item): array => $item->toArray(), $this->data),
            'meta' => $this->meta,
        ];
    }
}
