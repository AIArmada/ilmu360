<?php

declare(strict_types=1);

use App\Support\Api\Admin\AdminResourceMutationService;
use App\Support\Api\Admin\AdminResourceRegistry;
use App\Support\Api\Member\MemberResourceMutationService;
use App\Support\Api\SurfaceSyncPolicy;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the CRUD comparison JSON aligned with runtime panel resources and write support', function () {
    /** @var array<string, mixed> $document */
    $document = json_decode(
        file_get_contents(base_path('docs/ilmu360_api_mcp_filament_crud_comparison.json')) ?: '[]',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $adminMutationService = app(AdminResourceMutationService::class);
    $adminRegistry = app(AdminResourceRegistry::class);
    $memberMutationService = app(MemberResourceMutationService::class);

    $runtimeAdminResources = collect(Filament::getPanel('admin')->getResources())
        ->map(function (string $resourceClass) use ($adminMutationService): array {
            $modelClass = $resourceClass::getModel();
            $path = new ReflectionClass($resourceClass)->getFileName() ?: '';

            return [
                'resource_key' => Str::kebab(Str::pluralStudly(class_basename($modelClass))),
                'resource_class' => $resourceClass,
                'model_class' => $modelClass,
                'source' => str_starts_with($path, app_path())
                    ? 'app'
                    : (str_starts_with($path, base_path('vendor')) ? 'vendor' : 'other'),
                'page_keys' => array_values(array_keys($resourceClass::getPages())),
                'generic_admin_write' => $adminMutationService->supports($resourceClass),
            ];
        })
        ->sortBy('resource_key')
        ->values()
        ->all();

    $runtimeAhliResources = collect(Filament::getPanel('ahli')->getResources())
        ->map(function (string $resourceClass) use ($memberMutationService): array {
            $modelClass = $resourceClass::getModel();

            return [
                'resource_key' => Str::kebab(Str::pluralStudly(class_basename($modelClass))),
                'resource_class' => $resourceClass,
                'model_class' => $modelClass,
                'page_keys' => array_values(array_keys($resourceClass::getPages())),
                'generic_member_write' => $memberMutationService->supports($resourceClass),
            ];
        })
        ->sortBy('resource_key')
        ->values()
        ->all();

    $runtimeAdminWriteResources = collect($adminRegistry->resources())
        ->filter(fn (string $resourceClass): bool => $adminMutationService->supports($resourceClass))
        ->map(fn (string $resourceClass): string => Str::kebab(Str::pluralStudly(class_basename($resourceClass::getModel()))))
        ->sort()
        ->values()
        ->all();

    $documentedAdminResources = collect(data_get($document, 'runtime_inventory.admin_panel.resources', []))
        ->sortBy('resource_key')
        ->values()
        ->all();

    $documentedAhliResources = collect(data_get($document, 'runtime_inventory.ahli_panel.resources', []))
        ->sortBy('resource_key')
        ->values()
        ->all();

    expect($document['schema_version'])->toBe('2.6.0')
        ->and($document['markdown_companion'])->toBe('docs/ilmu360_api_mcp_filament_crud_comparison.md')
        ->and(data_get($document, 'mcp_specialized_tools'))->toBeArray()
        ->and(data_get($document, 'mcp_specialized_tools.purpose'))->toContain('MCP-exclusive tools')
        ->and(data_get($document, 'mcp_specialized_tools.ai_media_generation_tools.tools'))->toHaveCount(4)
        ->and(collect(data_get($document, 'mcp_specialized_tools.ai_media_generation_tools.tools', []))
            ->pluck('tool_name')
            ->all())->toBe([
                'AdminUploadEventCoverImageTool',
                'AdminUploadEventPosterImageTool',
                'MemberUploadEventCoverImageTool',
                'MemberUploadEventPosterImageTool',
            ])
        ->and(data_get($document, 'event_detail_parity_notes.0'))->toContain('active_change_notice')
        ->and(data_get($document, 'search_parity_notes.0'))->toContain('/api/v1/admin/events/search')
        ->and(data_get($document, 'search_parity_notes.1'))->toContain('speakers, institutions, and references')
        ->and(data_get($document, 'search_parity_notes.2'))->toContain('active plus verified visibility')
        ->and(data_get($document, 'workflow_api_families.public_discovery_and_form_contracts'))->toContain('GET /api/v1/references*')
        ->and(data_get($document, 'surface_sync_contract'))->toEqual(SurfaceSyncPolicy::manifest())
        ->and(data_get($document, 'runtime_inventory.admin_panel.resource_count'))->toBe(count($runtimeAdminResources))
        ->and(data_get($document, 'runtime_inventory.ahli_panel.resource_count'))->toBe(count($runtimeAhliResources))
        ->and($documentedAdminResources)->toEqual($runtimeAdminResources)
        ->and($documentedAhliResources)->toEqual($runtimeAhliResources)
        ->and(data_get($document, 'generic_admin_write_support.resource_keys'))->toEqual($runtimeAdminWriteResources)
        ->and(data_get($document, 'generic_member_write_support.resource_keys'))->toEqual(
            collect($runtimeAhliResources)
                ->filter(fn (array $resource): bool => $resource['generic_member_write'])
                ->pluck('resource_key')
                ->values()
                ->all()
        )
        ->and(data_get($document, 'transport_rules.admin_mcp.media_upload_transport'))->toBe('json_base64_descriptor')
        ->and(data_get($document, 'transport_rules.admin_mcp.destructive_clear_flags_supported'))->toBeFalse()
        ->and(data_get($document, 'transport_rules.admin_api.apply_defaults_preview'))->toBeTrue()
        ->and(data_get($document, 'transport_rules.admin_api.validation_feedback'))->toBeTrue()
        ->and(data_get($document, 'transport_rules.admin_mcp.apply_defaults'))->toBeTrue()
        ->and(data_get($document, 'transport_rules.admin_mcp.apply_defaults_preview_only'))->toBeTrue()
        ->and(data_get($document, 'transport_rules.admin_mcp.apply_defaults_persisted_writes'))->toBe('ignored')
        ->and(data_get($document, 'transport_rules.admin_mcp.event_route_key_wrapper_semantics.update_relation_arrays.empty_array'))->toBe('detach all relations for that alias')
        ->and(data_get($document, 'transport_rules.admin_mcp.validation_feedback'))->toBeTrue()
        ->and(data_get($document, 'transport_rules.admin_api.validation_remediation_details'))->toBe([
            'fix_plan',
            'remaining_blockers',
            'normalized_payload_preview',
            'can_retry',
        ])
        ->and(data_get($document, 'transport_rules.admin_mcp.validation_remediation_details'))->toBe([
            'fix_plan',
            'remaining_blockers',
            'normalized_payload_preview',
            'can_retry',
        ])
        ->and(data_get($document, 'transport_rules.member_mcp.validate_only'))->toBeTrue()
        ->and(data_get($document, 'transport_rules.member_mcp.related_records_tool'))->toBeTrue()
        ->and($document['maintenance_rule'])->toContain('workflow API controllers or contracts');
});

it('keeps the markdown companion anchored to the verified runtime model', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_api_mcp_filament_crud_comparison.md')) ?: '';
    $adminPanelResourceCount = count(Filament::getPanel('admin')->getResources());
    $ahliPanelResourceCount = count(Filament::getPanel('ahli')->getResources());

    expect($markdown)
        ->toContain('Runtime panel registration wins.')
        ->toContain('`GET /api/v1/references*`')
        ->toContain('Event detail parity note')
        ->toContain('Search parity note')
        ->toContain('GET /api/v1/admin/events/search')
        ->toContain('active_change_notice')
        ->toContain('change_announcements')
        ->toContain('replacement_event')
        ->toContain('Surface sync operating model')
        ->toContain('curated parity')
        ->toContain('workflow-first capabilities')
        ->toContain("Runtime admin resource inventory ({$adminPanelResourceCount} registered resources)")
        ->toContain("Runtime Ahli resource inventory ({$ahliPanelResourceCount} registered resources)")
        ->toContain('MCP media and preview semantics')
        ->toContain('`apply_defaults=1` is supported during preview requests')
        ->toContain('`apply_defaults` is supported on admin preview tools')
        ->toContain('It is ignored for persisted MCP writes')
        ->toContain('omitted or `null` preserves existing relations, `[]` detaches all')
        ->toContain('json_base64_descriptor')
        ->toContain('Validation failures in validate-only mode now return machine-readable remediation details')
        ->toContain('Validation failures now expose schema-driven `feedback`')
        ->toContain('shared specialized search services')
        ->toContain('Supports `validate_only` preview for updates.')
        ->toContain('Supports one-level related-record traversal.')
        ->toContain('Event moderation:')
        ->toContain('Report triage:')
        ->toContain('Contribution-request review:')
        ->toContain('Membership-claim review:')
        ->toContain('Contribution-request queue tools:')
        ->toContain('member-submit-membership-claim')
        ->toContain('MCP Specialized Tools (AI and Workflow Extensions)')
        ->toContain('AdminUploadEventCoverImageTool')
        ->toContain('AdminUploadEventPosterImageTool')
        ->toContain('MemberUploadEventCoverImageTool')
        ->toContain('MemberUploadEventPosterImageTool')
        ->toContain('AI Media Generation Tools')
        ->toContain('Why MCP-only: Specialized AI workflow')
        ->toContain('public read/list/detail; authenticated suggest update; follows')
        ->toContain('public read/search/detail with event-change projections');
});
