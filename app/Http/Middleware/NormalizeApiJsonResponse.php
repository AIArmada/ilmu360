<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Api\ApiJsonResponseNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiJsonResponse
{
    public function __construct(
        private readonly ApiJsonResponseNormalizer $normalizer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $this->normalizer->normalize($request, $response);
    }
}
