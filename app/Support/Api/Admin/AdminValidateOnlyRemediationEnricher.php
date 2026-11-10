<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use App\Models\User;
use App\Platform\Api\ApiErrorDetailsEnricher;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

final class AdminValidateOnlyRemediationEnricher implements ApiErrorDetailsEnricher
{
    public function enrich(Request $request, array $details, array $validationErrors): array
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
}
