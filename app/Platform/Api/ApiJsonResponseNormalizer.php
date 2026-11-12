<?php

declare(strict_types=1);

namespace App\Platform\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApiJsonResponseNormalizer
{
    public function __construct(
        private ApiErrorDetailsEnricher $errorDetailsEnricher,
    ) {}

    public function normalize(Request $request, Response $response): Response
    {
        if (! ApiResponseFactory::isApiRequest($request) || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload) || ! $this->shouldNormalize($payload)) {
            return $response;
        }

        $payload = $this->normalizeErrorPayload($request, $payload, $response->getStatusCode());
        $payload = $this->appendRequestId($payload, $request);

        $response->setData($payload);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldNormalize(array $payload): bool
    {
        return array_key_exists('data', $payload)
            || array_key_exists('message', $payload)
            || array_key_exists('error', $payload)
            || array_key_exists('errors', $payload)
            || array_key_exists('meta', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeErrorPayload(Request $request, array $payload, int $status): array
    {
        if ($status < 400) {
            return $payload;
        }

        $message = $this->resolveErrorMessage($payload, $status);
        $validationErrors = is_array($payload['errors'] ?? null) ? $payload['errors'] : null;
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        $error['code'] = is_string($error['code'] ?? null) && trim($error['code']) !== ''
            ? $error['code']
            : ApiResponseFactory::errorCodeForStatus($validationErrors !== null ? 422 : $status);
        $error['message'] = is_string($error['message'] ?? null) && trim($error['message']) !== ''
            ? $error['message']
            : $message;

        if ($validationErrors !== null) {
            $details = is_array($error['details'] ?? null) ? $error['details'] : [];
            $details['fields'] = $validationErrors;
            $details = $this->errorDetailsEnricher->enrich($request, $details, $validationErrors);
            $error['details'] = $details;
        }

        $payload['message'] = $message;
        $payload['error'] = $error;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function appendRequestId(array $payload, Request $request): array
    {
        $meta = $this->meta($payload);
        $meta['request_id'] = is_string($meta['request_id'] ?? null) && trim($meta['request_id']) !== ''
            ? $meta['request_id']
            : ApiResponseFactory::requestId($request);

        $payload['meta'] = $meta;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function meta(array $payload): array
    {
        return is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveErrorMessage(array $payload, int $status): string
    {
        $message = $payload['message'] ?? data_get($payload, 'error.message');

        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        return ApiResponseFactory::messageForStatus($status);
    }
}
