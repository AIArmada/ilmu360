<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Models\User;
use Filament\Resources\Resource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use UnitEnum;

/**
 * Generic Filament/Eloquent introspection shared by every resource definition.
 *
 * Nothing here selects a concrete owner model or resource: callers pass the
 * Filament resource class they own.
 */
final class ResourceIntrospection
{
    private const array COMMUNICATIONS_PRIVATE_ATTRIBUTES = [
        'action_url',
        'address',
        'attachable_id',
        'attachable_type',
        'body',
        'checksum',
        'communication_id',
        'content_html',
        'content_text',
        'content_id',
        'data',
        'destination_ciphertext',
        'destination_hash',
        'destination_hint',
        'device_label',
        'display_name',
        'entity_id',
        'entity_type',
        'external_id',
        'external_key',
        'external_thread_id',
        'failure_code',
        'failure_message',
        'filename',
        'idempotency_key',
        'metadata',
        'owner_id',
        'owner_type',
        'payload',
        'provider_account_key',
        'provider_message_id',
        'recipient_id',
        'recipient_type',
        'reason',
        'request_payload',
        'response_payload',
        'sender_id',
        'sender_type',
        'snapshot',
        'storage_disk',
        'storage_path',
        'subject',
        'subject_id',
        'subject_type',
        'template_id',
        'template_version_id',
        'target_url_ciphertext',
        'thread_id',
        'title',
        'token_hash',
        'variables_schema',
    ];

    /** @var array<class-string<resource>, list<string>> */
    private array $searchableColumnsCache = [];

    /** @param class-string<resource> $resourceClass */
    public function keyFor(string $resourceClass): string
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::getModel();

        return Str::kebab(Str::pluralStudly(class_basename($modelClass)));
    }

    /** @return list<string> */
    public function relationNames(string $resourceClass): array
    {
        $relationNames = [];

        foreach ($resourceClass::getRelations() as $relation) {
            $name = $this->relationName($relation);

            if (! filled($name)) {
                continue;
            }

            $relationNames[] = $name;
        }

        return array_values(array_unique($relationNames));
    }

    /** @return list<string> */
    public function searchableColumns(string $resourceClass): array
    {
        if (array_key_exists($resourceClass, $this->searchableColumnsCache)) {
            return $this->searchableColumnsCache[$resourceClass];
        }

        $modelClass = $resourceClass::getModel();
        $model = new $modelClass;
        $table = $model->getTable();
        $candidates = [
            $resourceClass::getRecordTitleAttribute(),
            $model->getRouteKeyName(),
            'name',
            'middle_name',
            'family_name',
            'title',
            'slug',
            'email',
        ];

        $normalizedCandidates = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $normalizedCandidates[] = $candidate;
        }

        $normalizedCandidates = array_values(array_unique($normalizedCandidates));

        return $this->searchableColumnsCache[$resourceClass] = array_values(array_filter(
            $normalizedCandidates,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
    }

    /** @return list<string> */
    public function apiRelations(Model $model): array
    {
        $relations = [];

        if (method_exists($model, 'addresses')) {
            $relations[] = 'addresses';
        }

        if (method_exists($model, 'contactMethods')) {
            $relations[] = 'contactMethods';
        }

        if (method_exists($model, 'socialProfiles')) {
            $relations[] = 'socialProfiles';
        }

        if (method_exists($model, 'names')) {
            $relations[] = 'names';
        }

        return $relations;
    }

    public function stringOrNull(string|UnitEnum|null $value): ?string
    {
        if ($value instanceof UnitEnum) {
            return $value instanceof \BackedEnum ? (string) $value->value : $value->name;
        }

        return filled($value) ? $value : null;
    }

    public function htmlableToString(Htmlable|string|null $value): ?string
    {
        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }

        return $value;
    }

    /**
     * Recursively strip the `responsive_images` key from any nested array in the serialized payload.
     * This prevents base64 SVG placeholders and large responsive-image URL lists from bloating MCP responses.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function stripResponsiveImages(array $data): array
    {
        unset($data['responsive_images']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->stripResponsiveImages($value);
            }
        }

        return $data;
    }

    public function isCommunicationsModel(Model $record): bool
    {
        return str_starts_with($record::class, 'AIArmada\\Communications\\Models\\');
    }

    public function communicationsRecordTitle(Model $record): ?string
    {
        return $this->isCommunicationsModel($record)
            ? Str::headline(class_basename($record))
            : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function sanitizeCommunicationsAttributes(Model $record, array $attributes): array
    {
        return $this->isCommunicationsModel($record)
            ? $this->removeCommunicationsPrivateAttributes($attributes)
            : $attributes;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    /** @param array<string, mixed> $parameters */
    public function safePanelUrl(string $resourceClass, string $page, array $parameters = []): ?string
    {
        if (! array_key_exists($page, $resourceClass::getPages())) {
            return null;
        }

        try {
            return $resourceClass::getUrl($page, $parameters, panel: 'admin');
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function resourcePanelUrl(string $resourceClass, string $page, Model $record): ?string
    {
        try {
            return $resourceClass::getUrl($page, ['record' => $record], panel: 'admin');
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /** @param class-string<resource> $resourceClass */
    public function canAccessResource(string $resourceClass, ?User $user): bool
    {
        return $this->canViewAny($resourceClass, $user)
            || $this->canCreate($resourceClass, $user)
            || $resourceClass::canDeleteAny()
            || $resourceClass::canForceDeleteAny()
            || $resourceClass::canRestoreAny()
            || $resourceClass::canReorder();
    }

    /** @param class-string<resource> $resourceClass */
    public function canCreate(string $resourceClass, ?User $user): bool
    {
        $modelClass = $resourceClass::getModel();

        return $user instanceof User
            ? $user->can('create', $modelClass)
            : $resourceClass::canCreate();
    }

    /** @param class-string<resource> $resourceClass */
    public function canViewAny(string $resourceClass, ?User $user): bool
    {
        $modelClass = $resourceClass::getModel();

        return $user instanceof User
            ? $user->can('viewAny', $modelClass)
            : $resourceClass::canViewAny();
    }

    /** @param class-string<resource> $resourceClass */
    public function canActOnRecord(string $ability, string $resourceClass, Model $record, ?User $user): bool
    {
        return $user instanceof User
            ? $user->can($ability, $record)
            : match ($ability) {
                'view' => $resourceClass::canView($record),
                'update' => $resourceClass::canEdit($record),
                'delete' => $resourceClass::canDelete($record),
                default => false,
            };
    }

    private function relationName(mixed $relation): ?string
    {
        $relationClass = is_object($relation) ? $relation::class : (is_string($relation) ? $relation : null);

        if (! is_string($relationClass) || $relationClass === '') {
            return null;
        }

        $reflection = new \ReflectionClass($relationClass);

        if ($reflection->hasProperty('relationship')) {
            $property = $reflection->getProperty('relationship');

            if ($property->isStatic()) {
                $value = $property->getValue();

                if (is_string($value) && $value !== '') {
                    return Str::snake($value);
                }
            }
        }

        return Str::snake(class_basename($relationClass));
    }

    /**
     * @param  array<int|string, mixed>  $attributes
     * @return array<int|string, mixed>
     */
    private function removeCommunicationsPrivateAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_string($key) && in_array($key, self::COMMUNICATIONS_PRIVATE_ATTRIBUTES, true)) {
                unset($attributes[$key]);

                continue;
            }

            if (is_array($value)) {
                $attributes[$key] = $this->removeCommunicationsPrivateAttributes($value);
            }
        }

        return $attributes;
    }
}
