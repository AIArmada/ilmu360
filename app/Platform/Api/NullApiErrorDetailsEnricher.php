<?php

declare(strict_types=1);

namespace App\Platform\Api;

use Illuminate\Http\Request;

final class NullApiErrorDetailsEnricher implements ApiErrorDetailsEnricher
{
    public function enrich(Request $request, array $details, array $validationErrors): array
    {
        return $details;
    }
}
