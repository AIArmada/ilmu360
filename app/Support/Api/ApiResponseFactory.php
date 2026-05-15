<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiResponseFactory
{
    public static function isApiRequest(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        return (is_string($routeName) && str_starts_with($routeName, 'api.'))
            || $request->is('api/*');
    }

    public static function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-ID');

        if (is_string($requestId) && trim($requestId) !== '') {
            return $requestId;
        }

        return (string) Str::uuid();
    }

    public static function errorCodeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            419 => 'page_expired',
            422 => 'validation_error',
            429 => 'rate_limited',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };
    }

    public static function messageForStatus(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be processed.',
            401 => 'Authentication is required to access this resource.',
            403 => 'You are not authorized to perform this action.',
            404 => 'The requested resource could not be found.',
            405 => 'This HTTP method is not allowed for the requested resource.',
            409 => 'The request conflicts with the current state of the resource.',
            419 => 'The session has expired. Please refresh and try again.',
            422 => 'The given data was invalid.',
            429 => 'Too many requests. Please try again later.',
            503 => 'The service is temporarily unavailable. Please try again later.',
            default => $status >= 500
                ? 'An unexpected server error occurred.'
                : 'The request could not be completed.',
        };
    }
}
