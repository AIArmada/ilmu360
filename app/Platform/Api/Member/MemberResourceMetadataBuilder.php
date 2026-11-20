<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Platform\Api\Member\Contracts\MemberResourceHooks;
use App\Platform\Api\Resources\ResourceIntrospection;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Builds the exact member resource metadata envelope from a member Filament
 * resource plus its owner hook.
 *
 * Member metadata intentionally differs from the admin envelope: it exposes no
 * filters or API routes, keeps create disabled, and uses Ahli panel routes.
 */
final class MemberResourceMetadataBuilder
{
    /** @var array<class-string<resource>, list<string>> */
    private array $searchableColumnsCache = [];

    public function __construct(
        private readonly MemberResourceHookRegistry $hooks,
        private readonly ResourceIntrospection $introspection,
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array{
     *   key: string,
     *   resource_class: class-string<resource>,
     *   model_class: class-string<Model>,
     *   model_label: string,
     *   plural_model_label: string,
     *   navigation_group: string|null,
     *   record_title_attribute: string|null,
     *   pages: array<string, bool>,
     *   relations: list<string>,
     *   abilities: array<string, bool>,
     *   write_support: array<string, bool>,
     *   mcp_tools: array<string, mixed>,
     *   panel_routes: array<string, string|null>,
     *   timezone_sensitive: bool,
     *   date_semantics: array<string, mixed>|null
     * }
     */
    public function build(
        string $resourceClass,
        string $key,
        MemberResourceHooks $hook,
        bool $canAccess,
        bool $canWrite,
    ): array {
        $pages = $resourceClass::getPages();
        $relations = $this->relationNames($resourceClass);
        $dateSemantics = $hook->dateSemantics($resourceClass);

        return [
            'key' => $key,
            'resource_class' => $resourceClass,
            'model_class' => $resourceClass::getModel(),
            'model_label' => $resourceClass::getModelLabel(),
            'plural_model_label' => $resourceClass::getPluralModelLabel(),
            'navigation_group' => $this->introspection->stringOrNull($resourceClass::getNavigationGroup()),
            'record_title_attribute' => $resourceClass::getRecordTitleAttribute(),
            'pages' => [
                'index' => array_key_exists('index', $pages),
                'create' => array_key_exists('create', $pages),
                'view' => array_key_exists('view', $pages),
                'edit' => array_key_exists('edit', $pages),
            ],
            'relations' => $relations,
            'abilities' => [
                'view_any' => $canAccess,
                'create' => false,
                'update' => $canWrite,
            ],
            'write_support' => [
                'schema' => $canWrite,
                'store' => false,
                'update' => $canWrite,
            ],
            'mcp_tools' => $this->mcpTools($resourceClass, $key, $canWrite, $dateSemantics, $relations, $hook),
            'panel_routes' => [
                'index' => array_key_exists('index', $pages) ? $resourceClass::getUrl('index', panel: 'ahli') : null,
                'create' => null,
                'view_template' => array_key_exists('view', $pages) ? $resourceClass::getUrl('view', ['record' => 'record'], panel: 'ahli') : null,
                'edit_template' => array_key_exists('edit', $pages) ? $resourceClass::getUrl('edit', ['record' => 'record'], panel: 'ahli') : null,
            ],
            'timezone_sensitive' => $dateSemantics !== null,
            'date_semantics' => $dateSemantics,
        ];
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return list<string>
     */
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

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>|null  $dateSemantics
     * @param  list<string>  $relations
     * @return array<string, mixed>
     */
    private function mcpTools(
        string $resourceClass,
        string $key,
        bool $canWrite,
        ?array $dateSemantics,
        array $relations,
        MemberResourceHooks $hook,
    ): array {
        $listRecordsArguments = [
            'resource_key' => $key,
            'search' => null,
            'page' => 1,
            'per_page' => 15,
        ];

        if ($dateSemantics !== null) {
            $listRecordsArguments['starts_after'] = null;
            $listRecordsArguments['starts_before'] = null;
            $listRecordsArguments['starts_on_local_date'] = null;
        }

        $ownerImageUploadTools = $hook->imageUploadMcpTools($resourceClass, $key);
        $imageUploadTools = [];

        foreach ($this->hooks->all() as $availableHook) {
            foreach ($availableHook->imageUploadMcpToolKeys() as $slot) {
                $imageUploadTools[$slot] = $ownerImageUploadTools[$slot] ?? null;
            }
        }

        return [
            'list_resources' => [
                'tool' => 'member-list-resources',
                'arguments' => [
                    'verbose' => true,
                ],
            ],
            'get_meta' => [
                'tool' => 'member-get-resource-meta',
                'arguments' => [
                    'resource_key' => $key,
                ],
            ],
            'list_records' => [
                'tool' => 'member-list-records',
                'arguments' => $listRecordsArguments,
            ],
            'list_related_records' => $relations !== []
                ? [
                    'tool' => 'member-list-related-records',
                    'arguments' => [
                        'resource_key' => $key,
                        'record_key' => 'record',
                        'relation' => 'relation',
                        'search' => null,
                        'page' => 1,
                        'per_page' => 15,
                    ],
                ]
                : null,
            'get_record' => [
                'tool' => 'member-get-record',
                'arguments' => [
                    'resource_key' => $key,
                    'record_key' => 'record',
                ],
            ],
            'get_record_actions' => [
                'tool' => 'member-get-record-actions',
                'arguments' => [
                    'resource_key' => $key,
                    'record_key' => 'record',
                ],
            ],
            ...$imageUploadTools,
            'get_update_schema' => $canWrite
                ? [
                    'tool' => 'member-get-write-schema',
                    'arguments' => [
                        'resource_key' => $key,
                        'record_key' => 'record',
                    ],
                ]
                : null,
            'update' => $canWrite
                ? [
                    'tool' => 'member-update-record',
                    'arguments' => [
                        'resource_key' => $key,
                        'record_key' => 'record',
                        'payload' => 'object',
                        'validate_only' => false,
                    ],
                ]
                : null,
        ];
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return list<string>
     */
    private function relationNames(string $resourceClass): array
    {
        $relationNames = [];

        foreach ($resourceClass::getRelations() as $relationKey => $relation) {
            $name = is_string($relationKey) && $relationKey !== ''
                ? Str::snake($relationKey)
                : $this->relationName($relation);

            if (! filled($name)) {
                continue;
            }

            $relationNames[] = $name;
        }

        foreach ($this->hooks->all() as $availableHook) {
            array_push($relationNames, ...$availableHook->extraRelations($resourceClass));
        }

        return array_values(array_unique($relationNames));
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
}
