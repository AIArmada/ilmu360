<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

interface SearchFilterRules
{
    /**
     * Build the Laravel validation rules for a primitive discovery filter payload.
     *
     * @return array<string, mixed>
     */
    public function rules(mixed $filters): array;
}
