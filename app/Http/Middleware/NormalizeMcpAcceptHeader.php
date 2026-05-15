<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeMcpAcceptHeader
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accept = $request->header('Accept', '');

        $values = array_values(array_filter(
            array_map(trim(...), explode(',', is_string($accept) ? $accept : '')),
            static fn (string $value): bool => $value !== ''
        ));

        if (! in_array('application/json', $values, true)) {
            array_unshift($values, 'application/json');
        }

        usort($values, static fn (string $left, string $right): int => str_contains($right, 'application/json') <=> str_contains($left, 'application/json'));

        $request->headers->set('Accept', implode(', ', array_unique($values)));

        return $next($request);
    }
}
