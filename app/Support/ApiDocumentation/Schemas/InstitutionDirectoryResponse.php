<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
#[SchemaName('InstitutionDirectoryResponse')]
final readonly class InstitutionDirectoryResponse implements Arrayable
{
    /**
     * @param  list<InstitutionListItem>  $data
     * @param  array{pagination: array{page: int, per_page: int, total: int}, following?: array{total: int}, location?: array{active: bool, lat: ?float, lng: ?float, radius_km: ?int}, types?: list<array{value: string, label: string}>, cache: array{version: string}, request_id: string}  $meta
     */
    public function __construct(
        public array $data,
        public array $meta,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{pagination: array{page: int, per_page: int, total: int}, following?: array{total: int}, location?: array{active: bool, lat: ?float, lng: ?float, radius_km: ?int}, types?: list<array{value: string, label: string}>, cache: array{version: string}, request_id: string}}
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (InstitutionListItem $item): array => $item->toArray(), $this->data),
            'meta' => $this->meta,
        ];
    }
}
