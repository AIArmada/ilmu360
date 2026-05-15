<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-import-type InstitutionDetailPageArray from InstitutionDetailPage
 *
 * @phpstan-type InstitutionDetailResponseArray array{data: InstitutionDetailPageArray, meta: array{request_id: string}}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('InstitutionDetailResponse')]
final readonly class InstitutionDetailResponse implements Arrayable
{
    /**
     * @param  array{request_id: string}  $meta
     */
    public function __construct(
        public InstitutionDetailPage $data,
        public array $meta,
    ) {}

    /** @return InstitutionDetailResponseArray */
    public function toArray(): array
    {
        return [
            'data' => $this->data->toArray(),
            'meta' => $this->meta,
        ];
    }
}
