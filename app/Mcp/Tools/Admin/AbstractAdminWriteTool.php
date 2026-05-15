<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Api\Admin\AdminResourceService;
use App\Support\Api\Admin\AdminValidateOnlyRemediationPlanner;
use App\Support\Api\Admin\AdminWriteValidationFeedback;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use App\Support\Mcp\McpFilePayloadNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

abstract class AbstractAdminWriteTool extends AbstractAdminTool
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schemaResponse
     * @return array<string, mixed>
     */
    protected function payloadWithSchemaDefaults(array $payload, array $schemaResponse): array
    {
        return app(AdminWriteValidationFeedback::class)->payloadWithSchemaDefaults($payload, $schemaResponse);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schemaResponse
     */
    protected function writeValidationErrorResponse(
        ValidationException $exception,
        array $payload,
        array $schemaResponse,
        string $resourceKey,
        string $operation,
        bool $validateOnly,
        bool $applyDefaults,
    ): ResponseFactory {
        $feedback = app(AdminWriteValidationFeedback::class);
        $candidatePayload = $validateOnly && $applyDefaults
            ? $payload
            : null;

        $details = [
            'errors' => $exception->errors(),
            'feedback' => $feedback->feedback(
                $exception,
                $payload,
                $schemaResponse,
                $operation,
                $validateOnly,
                $applyDefaults,
                $candidatePayload,
            ),
        ];

        if ($validateOnly) {
            $details = [
                ...$details,
                ...app(AdminValidateOnlyRemediationPlanner::class)->build(
                    payload: $payload,
                    schemaResponse: $schemaResponse,
                    errors: $exception->errors(),
                ),
            ];
        }

        return $this->errorResponse(
            $feedback->message($exception),
            'validation_error',
            $details,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ensureDestructiveMediaClearFlagsAreUnsupported(array $payload): void
    {
        $errors = [];

        foreach (['clear_logo', 'clear_cover', 'clear_avatar', 'clear_poster', 'clear_front_cover', 'clear_back_cover', 'clear_gallery', 'clear_main', 'clear_qr', 'clear_evidence'] as $field) {
            if (! array_key_exists($field, $payload) || ! $this->hasMeaningfulMediaValue($payload[$field])) {
                continue;
            }

            $errors[$field] = ['Destructive media clear flags are not supported through MCP. Upload a replacement file or array when the schema advertises that media field.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizePayloadForWriteTool(string $resourceKey, array $payload): array
    {
        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schemaResponse
     * @return array{payload: array<string, mixed>, temporary_paths: list<string>}
     */
    protected function normalizeMcpMediaPayload(array $payload, array $schemaResponse): array
    {
        return app(McpFilePayloadNormalizer::class)->normalize(
            $payload,
            $this->mediaFieldContractsFromSchemaResponse($schemaResponse),
        );
    }

    /**
     * @param  array{temporary_paths?: list<string>}  $normalizedMediaPayload
     */
    protected function cleanupMcpMediaPayload(array $normalizedMediaPayload): void
    {
        app(McpFilePayloadNormalizer::class)->cleanup($normalizedMediaPayload['temporary_paths'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $schemaResponse
     * @return array<string, array<string, mixed>>
     */
    private function mediaFieldContractsFromSchemaResponse(array $schemaResponse): array
    {
        $fields = Arr::get($schemaResponse, 'data.schema.fields', []);

        if (! is_array($fields)) {
            return [];
        }

        $contracts = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            /** @var array<string, mixed> $field */
            $name = $field['name'] ?? null;
            $type = $field['type'] ?? null;

            if (! is_string($name) || ! is_string($type) || ! str_contains($type, 'file')) {
                continue;
            }

            $contracts[$name] = $field;
        }

        return $contracts;
    }

    public function shouldRegister(Request $request, AdminResourceService $resourceService): bool
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user instanceof User && $resourceService->hasAnyWritableResourceAccess($user);
    }

    protected function hasMeaningfulMediaValue(mixed $value): bool
    {
        return match (true) {
            is_string($value) => trim($value) !== '',
            is_array($value) => $value !== [],
            is_bool($value) => $value,
            default => $value !== null,
        };
    }

    protected function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function normalizeOrganizerType(mixed $value): ?string
    {
        return match ($value) {
            'institution', Institution::class => Institution::class,
            'speaker', Speaker::class => Speaker::class,
            default => null,
        };
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function resolveRecordIdentifier(string $field, string $modelClass, string $key): string
    {
        /** @var Model $model */
        $model = new $modelClass;

        foreach ($this->lookupColumns($model) as $column) {
            if (! $this->canLookupWithValue($model, $column, $key)) {
                continue;
            }

            $record = $modelClass::query()->where($column, $key)->first();

            if ($record instanceof Model) {
                return (string) $record->getKey();
            }
        }

        throw ValidationException::withMessages([
            $field => __('The selected record key is invalid.'),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function lookupColumns(Model $model): array
    {
        $columns = [];

        if (in_array('slug', $model->getFillable(), true)) {
            $columns[] = 'slug';
        }

        foreach ([$model->getRouteKeyName(), $model->getKeyName()] as $column) {
            if (! in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    protected function canLookupWithValue(Model $model, string $column, string $value): bool
    {
        $keyName = $model->getKeyName();

        if ($column !== $keyName) {
            return true;
        }

        if ($model->getKeyType() === 'int') {
            return ctype_digit($value);
        }

        if ($keyName === 'id') {
            return Str::isUuid($value) || Str::isUlid($value);
        }

        return true;
    }

    /**
     * @param  class-string<\BackedEnum>  $enumClass
     * @return list<string>
     */
    protected function enumValues(string $enumClass): array
    {
        return array_values(array_column($enumClass::cases(), 'value'));
    }
}
