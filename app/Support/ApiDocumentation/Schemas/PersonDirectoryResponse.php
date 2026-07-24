<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type PersonListItemArray from PersonListItem
 *
 * @phpstan-type PersonDirectoryResponseArray array{data: list<PersonListItemArray>, meta: array{pagination: array{page: int, per_page: int, total: int}, following: array{total: int}, cache: array{version: string}, request_id: string}}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('PersonDirectoryResponse')]
final readonly class PersonDirectoryResponse implements Arrayable
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
