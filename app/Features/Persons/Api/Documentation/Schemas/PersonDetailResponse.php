<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Documentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;

/**
 * @phpstan-import-type PersonDetailPageArray from PersonDetailPage
 *
 * @phpstan-type PersonDetailResponseArray array{data: PersonDetailPageArray, meta: array{request_id: string}}
 */
#[SchemaName('PersonDetailResponse')]
final readonly class PersonDetailResponse
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
