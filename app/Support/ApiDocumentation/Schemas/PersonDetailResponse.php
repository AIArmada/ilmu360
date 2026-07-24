<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type PersonDetailPageArray from PersonDetailPage
 *
 * @phpstan-type PersonDetailResponseArray array{data: PersonDetailPageArray, meta: array{request_id: string}}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('PersonDetailResponse')]
final readonly class PersonDetailResponse implements Arrayable
{
    /**
     * @param  array{request_id: string}  $meta
     */
    public function __construct(
        public PersonDetailPage $data,
        public array $meta,
    ) {}

    /** @return PersonDetailResponseArray */
    public function toArray(): array
    {
        return [
            'data' => $this->data->toArray(),
            'meta' => $this->meta,
        ];
    }
}
