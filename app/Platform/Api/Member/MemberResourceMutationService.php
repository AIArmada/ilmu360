<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Features\Membership\Api\MemberPermissions;
use App\Models\User;
use App\Platform\Api\Admin\AdminResourceMutationService;
use App\Platform\Mcp\McpWriteSchemaFormatter;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MemberResourceMutationService
{
    public function __construct(
        private readonly AdminResourceMutationService $adminMutationService,
        private readonly MemberPermissions $memberPermissions,
        private readonly MemberResourceHookRegistry $hooks,
        private readonly McpWriteSchemaFormatter $schemaFormatter,
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function supports(string $resourceClass): bool
    {
        return array_any($this->hooks->all(), fn ($hooks) => $hooks->adminResourceClass($resourceClass) !== null);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function canWriteResource(string $resourceClass, ?User $user = null): bool
    {
        if (! $user instanceof User || ! $this->supports($resourceClass)) {
            return false;
        }

        foreach ($this->hooks->all() as $hooks) {
            $allowed = $hooks->canWriteResource($resourceClass, $user, $this->memberPermissions);

            if ($allowed !== null) {
                return $allowed;
            }
        }

        return false;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function canWriteRecord(string $resourceClass, User $user, Model $record): bool
    {
        if (! $this->supports($resourceClass)) {
            return false;
        }

        foreach ($this->hooks->all() as $hooks) {
            $allowed = $hooks->canWriteRecord($resourceClass, $user, $record);

            if ($allowed !== null) {
                return $allowed;
            }
        }

        return $user->can('update', $record);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function schema(string $resourceClass, string $resourceKey, Model $record): array
    {
        $schema = $this->adminMutationService->schema(
            $this->adminResourceClass($resourceClass),
            $resourceKey,
            'update',
            $record,
        );

        return $this->schemaFormatter->formatSchema($schema, 'member-update-record', [
            'resource_key' => $resourceKey,
            'record_key' => (string) $record->getRouteKey(),
            'payload' => 'object',
            'validate_only' => false,
        ]);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function rules(string $resourceClass): array
    {
        return $this->adminMutationService->rules(
            $this->adminResourceClass($resourceClass),
            updating: true,
        );
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeValidatedPayload(string $resourceClass, array $validated, ?Model $record = null): array
    {
        return $this->adminMutationService->normalizeValidatedPayload(
            $this->adminResourceClass($resourceClass),
            $validated,
            $record,
        );
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function update(string $resourceClass, Model $record, array $validated, User $actor): Model
    {
        return $this->adminMutationService->update(
            $this->adminResourceClass($resourceClass),
            $record,
            $validated,
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{normalized_payload: array<string, mixed>, warnings: list<array<string, string>>, destructive_media_fields: list<string>}
     */
    public function previewNormalizedPayload(array $normalizedPayload): array
    {
        return $this->adminMutationService->previewNormalizedPayload($normalizedPayload);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return class-string<resource>
     */
    private function adminResourceClass(string $resourceClass): string
    {
        foreach ($this->hooks->all() as $hooks) {
            $admin = $hooks->adminResourceClass($resourceClass);

            if ($admin !== null) {
                return $admin;
            }
        }

        throw new NotFoundHttpException;
    }
}
