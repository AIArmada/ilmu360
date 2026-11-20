<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Models\User;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shared admin write orchestration for owner resource writers.
 *
 * Permission checks, validate-only semantics and the existing response
 * fragments live here; domain schema, rules, normalization and persistence are
 * implemented by the owner writer.
 */
abstract class AbstractAdminResourceWriter implements ResourceWriter
{
    /** @param class-string<resource> $resourceClass */
    public function __construct(
        protected readonly ResourceDefinitionContext $context,
        protected readonly string $resourceClass,
    ) {}

    public function surface(): ResourceSurface
    {
        return ResourceSurface::Admin;
    }

    public function key(): string
    {
        return $this->context->introspection->keyFor($this->resourceClass);
    }

    /** @return class-string<resource> */
    public function resourceClass(): string
    {
        return $this->resourceClass;
    }

    /** @return array<string, mixed> */
    public function schema(ResourceActor $actor, string $operation, ?string $recordId): array
    {
        $user = $this->context->actors->user($actor);

        abort_unless($user instanceof User, 403);
        abort_unless(in_array($operation, ['create', 'update'], true), 422);

        $record = null;

        if ($operation === 'create') {
            abort_unless($user->can('create', $this->resourceClass::getModel()), 403);
        } else {
            if (! is_string($recordId) || trim($recordId) === '') {
                throw ValidationException::withMessages([
                    'recordKey' => [__('Record key is required when operation is update.')],
                ]);
            }

            $record = $this->context->queries->resolveRecord($this->resourceClass, trim($recordId));
            abort_unless($user->can('update', $record), 403);
        }

        return $this->schemaFor($this->key(), $operation, $record);
    }

    /** @return array<string, mixed> */
    public function validateAndPreview(ResourceActor $actor, string $operation, ?string $recordId, array $payload): array
    {
        $user = $this->context->actors->user($actor);

        abort_unless($user instanceof User, 403);
        abort_unless(in_array($operation, ['create', 'update'], true), 422);

        $record = null;

        if ($operation === 'create') {
            abort_unless($user->can('create', $this->resourceClass::getModel()), 403);
        } else {
            if (! is_string($recordId) || trim($recordId) === '') {
                throw ValidationException::withMessages([
                    'recordKey' => [__('Record key is required when operation is update.')],
                ]);
            }

            $record = $this->context->queries->resolveRecord($this->resourceClass, trim($recordId));
            abort_unless($user->can('update', $record), 403);
        }

        $validated = $this->validatePayload($payload, $operation === 'update', $record);

        return $this->previewResponse($operation, $validated, $record, $user);
    }

    /** @return array<string, mixed> */
    public function write(ResourceActor $actor, string $operation, ?string $recordId, array $payload): array
    {
        $user = $this->context->actors->user($actor);

        abort_unless($user instanceof User, 403);

        if ($operation === 'create') {
            abort_unless($user->can('create', $this->resourceClass::getModel()), 403);

            $validated = $this->validatePayload($payload, updating: false);
            $record = $this->storeModel($user, $validated);
        } elseif ($operation === 'update') {
            if (! is_string($recordId) || trim($recordId) === '') {
                throw ValidationException::withMessages([
                    'recordKey' => [__('Record key is required when operation is update.')],
                ]);
            }

            $record = $this->context->queries->resolveRecord($this->resourceClass, trim($recordId));
            abort_unless($user->can('update', $record), 403);

            $validated = $this->validatePayload($payload, updating: true, record: $record);
            $record = $this->updateModel($user, $record, $validated);
        } else {
            abort(422);
        }

        return [
            'record_id' => (string) $record->getKey(),
            'record' => $this->context->serializer->serializeRecordDetail($this->resourceClass, $record, $user),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validatePayload(array $payload, bool $updating, ?Model $record = null): array
    {
        $validated = Validator::make(
            $payload,
            $this->rulesFor($updating),
        )->validate();

        if (is_array($payload['address'] ?? null) && ! array_key_exists('address', $validated)) {
            $validated['address'] = $payload['address'];
        }

        return $this->normalizeFor($validated, $record);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function previewResponse(string $operation, array $validated, ?Model $record, User $user): array
    {
        return array_merge(
            [
                'validate_only' => true,
                'operation' => $operation,
                'current_record' => $record instanceof Model
                    ? $this->context->serializer->serializeRecordDetail($this->resourceClass, $record, $user)
                    : null,
            ],
            $this->context->writerSupport->previewNormalizedPayload($validated),
        );
    }

    /** @return array<string, mixed> */
    abstract public function schemaFor(string $resourceKey, string $operation, ?Model $record): array;

    /** @return array<string, mixed> */
    abstract public function rulesFor(bool $updating): array;

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    abstract public function normalizeFor(array $validated, ?Model $record): array;

    /** @return array<string, mixed> */
    abstract public function defaultsForCreate(): array;

    /** @return array<string, mixed> */
    abstract public function defaultsForRecord(Model $record): array;

    /** @param array<string, mixed> $validated */
    abstract public function storeModel(User $actor, array $validated): Model;

    /** @param array<string, mixed> $validated */
    abstract public function updateModel(User $actor, Model $record, array $validated): Model;

    protected function routeKeyOf(Model $record): string
    {
        return (string) $record->getRouteKey();
    }

    protected function endpoint(string $resourceKey, string $operation, ?Model $record): string
    {
        return $operation === 'update' && $record instanceof Model
            ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->routeKeyOf($record)], false)
            : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false);
    }

    protected function writeSupport(): EloquentResourceWriterSupport
    {
        return $this->context->writerSupport;
    }
}
