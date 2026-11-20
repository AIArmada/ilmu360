<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Models\User;
use App\Platform\Api\Admin\AdminResourceMutationService;
use App\Platform\Mcp\McpWriteSchemaFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Member write mechanics shared by every owner definition.
 *
 * Member writes are update-only, reuse the generic admin writer for domain
 * schema/rules/normalization/persistence, and are wrapped in the member MCP
 * transport metadata.
 */
final readonly class MemberResourceWriter
{
    public function __construct(
        private AdminResourceMutationService $adminMutationService,
        private McpWriteSchemaFormatter $schemaFormatter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schemaForRecord(
        AbstractMemberResourceDefinition $definition,
        string $resourceKey,
        Model $record,
    ): array {
        $schema = $this->adminMutationService->schema(
            $this->adminResourceClass($definition),
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function previewForRecord(
        AbstractMemberResourceDefinition $definition,
        User $actor,
        Model $record,
        array $payload,
    ): array {
        $validated = $this->validateAndNormalize($definition, $payload, $record);

        return array_merge(
            [
                'validate_only' => true,
                'operation' => 'update',
                'current_record' => $definition->serializeRecordDetailFor($actor, $record),
            ],
            $this->adminMutationService->previewNormalizedPayload($validated),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function writeForRecord(
        AbstractMemberResourceDefinition $definition,
        User $actor,
        Model $record,
        array $payload,
    ): Model {
        $validated = $this->validateAndNormalize($definition, $payload, $record);

        return $this->adminMutationService->update(
            $this->adminResourceClass($definition),
            $record,
            $validated,
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateAndNormalize(
        AbstractMemberResourceDefinition $definition,
        array $payload,
        Model $record,
    ): array {
        $adminResourceClass = $this->adminResourceClass($definition);

        $validated = Validator::make(
            $payload,
            $this->adminMutationService->rules($adminResourceClass, updating: true),
        )->validate();

        if (is_array($payload['address'] ?? null) && ! array_key_exists('address', $validated)) {
            $validated['address'] = $payload['address'];
        }

        return $this->adminMutationService->normalizeValidatedPayload($adminResourceClass, $validated, $record);
    }

    /**
     * @return class-string
     */
    private function adminResourceClass(AbstractMemberResourceDefinition $definition): string
    {
        return $definition->adminResourceClass() ?? throw new NotFoundHttpException;
    }
}
