<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Models\User;
use App\Platform\Api\Admin\AdminResourceHookRegistry;
use Filament\Resources\Resource;
use Illuminate\Support\Arr;

/**
 * Builds the exact admin resource metadata envelope from a Filament resource
 * plus the owner-provided hooks.
 */
final class AdminResourceMetadataBuilder
{
    /** @var array<class-string<resource>, list<array{key: string, label: string, type: string, options?: array<string, string>}>> */
    private array $filtersCache = [];

    public function __construct(
        private readonly AdminResourceHookRegistry $hooks,
        private readonly ResourceIntrospection $introspection,
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function build(string $resourceClass, bool $supportsMutation, ?User $user): array
    {
        $key = $this->introspection->keyFor($resourceClass);
        $pages = $resourceClass::getPages();
        $filters = $this->filterMetadata($resourceClass);
        $dateSemantics = $this->dateSemanticsFor($resourceClass);
        $modelClass = $resourceClass::getModel();

        return [
            'key' => $key,
            'resource_class' => $resourceClass,
            'model_class' => $modelClass,
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
            'relations' => $this->introspection->relationNames($resourceClass),
            'abilities' => [
                'view_any' => $this->introspection->canViewAny($resourceClass, $user),
                'create' => $this->introspection->canCreate($resourceClass, $user),
                'delete_any' => $resourceClass::canDeleteAny(),
                'force_delete_any' => $resourceClass::canForceDeleteAny(),
                'restore_any' => $resourceClass::canRestoreAny(),
                'reorder' => $resourceClass::canReorder(),
            ],
            'filters' => $filters,
            'write_support' => [
                'schema' => $supportsMutation,
                'store' => $supportsMutation,
                'update' => $supportsMutation,
            ],
            'api_routes' => [
                'collection' => route('api.admin.resources.index', ['resourceKey' => $key], false),
                'meta' => route('api.admin.resources.meta', ['resourceKey' => $key], false),
                'schema' => $supportsMutation
                    ? route('api.admin.resources.schema', ['resourceKey' => $key], false)
                    : null,
                'store' => $supportsMutation && array_key_exists('create', $pages)
                    ? route('api.admin.resources.store', ['resourceKey' => $key], false)
                    : null,
                'related_collection' => route('api.admin.resources.related', ['resourceKey' => $key, 'recordKey' => 'record', 'relation' => 'relation'], false),
                'item_template' => route('api.admin.resources.show', ['resourceKey' => $key, 'recordKey' => 'record'], false),
                'update_template' => $supportsMutation && array_key_exists('edit', $pages)
                    ? route('api.admin.resources.update', ['resourceKey' => $key, 'recordKey' => 'record'], false)
                    : null,
            ],
            'mcp_tools' => $this->mcpTools($resourceClass, $key, $supportsMutation, $pages, $dateSemantics, $filters),
            'panel_routes' => [
                'index' => $this->introspection->safePanelUrl($resourceClass, 'index'),
                'create' => $this->introspection->safePanelUrl($resourceClass, 'create'),
                'view_template' => $this->introspection->safePanelUrl($resourceClass, 'view', ['record' => 'record']),
                'edit_template' => $this->introspection->safePanelUrl($resourceClass, 'edit', ['record' => 'record']),
            ],
            'timezone_sensitive' => $dateSemantics !== null,
            'date_semantics' => $dateSemantics,
        ];
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    public function filtersFor(string $resourceClass): array
    {
        if (array_key_exists($resourceClass, $this->filtersCache)) {
            return $this->filtersCache[$resourceClass];
        }

        return $this->filtersCache[$resourceClass] = $this->hooks->for($resourceClass)?->filters($resourceClass) ?? [];
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    public function filterMetadata(string $resourceClass): array
    {
        return array_map(
            static fn (array $filter): array => Arr::only($filter, ['key', 'label', 'type', 'options']),
            $this->filtersFor($resourceClass),
        );
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>|null
     */
    public function dateSemanticsFor(string $resourceClass): ?array
    {
        return $this->hooks->for($resourceClass)?->dateSemantics($resourceClass);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $pages
     * @param  array<string, mixed>|null  $dateSemantics
     * @param  list<array{key: string, label: string, type: string, options?: array<string, string>}>  $filters
     * @return array<string, mixed>
     */
    private function mcpTools(string $resourceClass, string $key, bool $supportsMutation, array $pages, ?array $dateSemantics, array $filters): array
    {
        $listRecordsArguments = [
            'resource_key' => $key,
            'search' => null,
            'page' => 1,
            'per_page' => 15,
        ];

        if ($filters !== []) {
            $listRecordsArguments['filters'] = 'object';
        }

        if ($dateSemantics !== null) {
            $listRecordsArguments['starts_after'] = null;
            $listRecordsArguments['starts_before'] = null;
            $listRecordsArguments['starts_on_local_date'] = null;
        }

        $hook = $this->hooks->for($resourceClass);
        $ownerImageUploadTools = $hook?->imageUploadMcpTools($resourceClass, $key) ?? [];
        $imageUploadTools = [];

        foreach ($this->hooks->all() as $availableHook) {
            foreach ($availableHook->imageUploadMcpToolKeys() as $slot) {
                $imageUploadTools[$slot] = $ownerImageUploadTools[$slot] ?? null;
            }
        }

        return [
            'list_resources' => [
                'tool' => 'admin-list-resources',
                'arguments' => [
                    'verbose' => true,
                    'writable_only' => false,
                ],
            ],
            'get_meta' => [
                'tool' => 'admin-get-resource-meta',
                'arguments' => [
                    'resource_key' => $key,
                ],
            ],
            'list_records' => [
                'tool' => 'admin-list-records',
                'arguments' => $listRecordsArguments,
            ],
            'list_related_records' => [
                'tool' => 'admin-list-related-records',
                'arguments' => [
                    'resource_key' => $key,
                    'record_key' => 'record',
                    'relation' => 'relation',
                    'search' => null,
                    'page' => 1,
                    'per_page' => 15,
                ],
            ],
            'get_record' => [
                'tool' => 'admin-get-record',
                'arguments' => [
                    'resource_key' => $key,
                    'record_key' => 'record',
                ],
            ],
            'get_record_actions' => [
                'tool' => 'admin-get-record-actions',
                'arguments' => [
                    'resource_key' => $key,
                    'record_key' => 'record',
                ],
            ],
            ...$imageUploadTools,
            'get_create_schema' => $supportsMutation
                ? [
                    'tool' => 'admin-get-write-schema',
                    'arguments' => [
                        'resource_key' => $key,
                        'operation' => 'create',
                    ],
                ]
                : null,
            'get_update_schema' => $supportsMutation
                ? [
                    'tool' => 'admin-get-write-schema',
                    'arguments' => [
                        'resource_key' => $key,
                        'operation' => 'update',
                        'record_key' => 'record',
                    ],
                ]
                : null,
            'create' => $supportsMutation && array_key_exists('create', $pages)
                ? [
                    'tool' => 'admin-create-record',
                    'arguments' => [
                        'resource_key' => $key,
                        'payload' => 'object',
                        'validate_only' => false,
                        'apply_defaults' => false,
                    ],
                ]
                : null,
            'update' => $supportsMutation && array_key_exists('edit', $pages)
                ? [
                    'tool' => 'admin-update-record',
                    'arguments' => [
                        'resource_key' => $key,
                        'record_key' => 'record',
                        'payload' => 'object',
                        'validate_only' => false,
                        'apply_defaults' => false,
                    ],
                ]
                : null,
        ];
    }
}
