<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\User;
use App\Support\Api\Admin\AdminResourceService;
use App\Support\Api\Admin\AdminValidateOnlyRemediationPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApiJsonResponseNormalizer
{
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
            $details = $this->withAdminValidateOnlyRemediation($request, $details, $validationErrors);
            $error['details'] = $details;
        }

        $payload['message'] = $message;
        $payload['error'] = $error;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $validationErrors
     * @return array<string, mixed>
     */
    private function withAdminValidateOnlyRemediation(Request $request, array $details, array $validationErrors): array
    {
        $routeName = $request->route()?->getName();

        if (! $request->boolean('validate_only')
            || ! in_array($routeName, ['api.admin.resources.store', 'api.admin.resources.update'], true)
            || array_key_exists('fix_plan', $details)) {
            return $details;
        }

        $resourceKey = $request->route('resourceKey');

        if (! is_string($resourceKey) || trim($resourceKey) === '') {
            return $details;
        }

        $recordKey = $request->route('recordKey');
        $actor = $request->user();

        try {
            return array_merge(
                $details,
                app(AdminValidateOnlyRemediationPlanner::class)->build(
                    payload: Arr::except($request->all(), ['validate_only']),
                    schemaResponse: app(AdminResourceService::class)->writeSchema(
                        resourceKey: $resourceKey,
                        operation: $routeName === 'api.admin.resources.store' ? 'create' : 'update',
                        recordKey: is_string($recordKey) ? $recordKey : null,
                        actor: $actor instanceof User ? $actor : null,
                    ),
                    errors: $validationErrors,
                ),
            );
        } catch (Throwable $exception) {
            report($exception);

            logger()->warning('Admin validate_only remediation enrichment failed.', [
                'route_name' => $routeName,
                'resource_key' => $resourceKey,
            ]);

            return $details;
        }
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
