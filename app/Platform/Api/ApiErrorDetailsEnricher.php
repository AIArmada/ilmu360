<?php

declare(strict_types=1);

namespace App\Platform\Api;

use Illuminate\Http\Request;

/** Adds generic error details for failed API requests without owning domain validation. */
interface ApiErrorDetailsEnricher
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $validationErrors
     * @return array<string, mixed>
     */
    public function enrich(Request $request, array $details, array $validationErrors): array;
}
