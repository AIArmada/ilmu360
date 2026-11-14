<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

final readonly class EventMediaExtractionResult
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(private array $fields) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
