<?php

declare(strict_types=1);

namespace App\Support\Api;

final class SurfaceSyncPolicy
{
    /**
     * @return array{
     *   strategy: string,
     *   adapter_rule: string,
     *   capability_classes: list<array{key: string, description: string, default_surfaces: list<string>, implementation_rule: string}>,
     *   workflow_first_capabilities: list<string>,
     *   default_panel_only_operations: list<string>,
     *   default_excluded_resource_groups: list<array{key: string, resource_keys: list<string>, rule: string}>,
     *   maintenance_triggers: list<string>,
     *   same_change_set_artifacts: list<string>,
     *   change_checklist: list<string>
     * }
     */
    public static function manifest(): array
    {
        return [
            'strategy' => 'curated_parity',
            'adapter_rule' => 'Keep validation, normalization, side effects, and orchestration in shared actions/services; panel, API, and MCP should remain thin adapters over the same backend behavior.',
            'capability_classes' => [
                [
                    'key' => 'panel_only',
                    'description' => 'Operational or destructive capabilities that stay in Filament unless a later product and security decision promotes them.',
                    'default_surfaces' => ['admin_panel', 'ahli_panel'],
                    'implementation_rule' => 'Do not mirror these capabilities to API or MCP by default.',
                ],
                [
                    'key' => 'generic_admin_crud',
                    'description' => 'Schema-guided admin CRUD for stable application resources.',
                    'default_surfaces' => ['admin_panel', 'admin_api', 'admin_mcp'],
                    'implementation_rule' => 'Expand through shared admin mutation services so HTTP and MCP move together.',
                ],
                [
                    'key' => 'member_scoped_crud',
                    'description' => 'Scoped edit capabilities for member-owned or member-linked records.',
                    'default_surfaces' => ['ahli_panel', 'member_mcp'],
                    'implementation_rule' => 'Keep member scope and permission checks aligned with Ahli resources.',
                ],
                [
                    'key' => 'workflow_api',
                    'description' => 'Public or authenticated workflow contracts such as contributions, membership claims, account settings, and workspace actions.',
                    'default_surfaces' => ['public_api', 'authenticated_api'],
                    'implementation_rule' => 'Mirror into Ahli panel or Member MCP only when the flow is genuine member self-service, not merely because generic CRUD exists.',
                ],
                [
                    'key' => 'workflow_action',
                    'description' => 'Explicit review, moderation, approval, or state-transition actions.',
                    'default_surfaces' => ['admin_panel', 'admin_api', 'admin_mcp', 'ahli_panel', 'member_mcp'],
                    'implementation_rule' => 'Expose these as named actions on eligible surfaces instead of overloading generic create or update.',
                ],
            ],
            'workflow_first_capabilities' => [
                'event moderation',
                'contribution review',
                'membership-claim review',
                'report triage',
            ],
            'default_panel_only_operations' => [
                'delete',
                'restore',
                'replicate',
                'reorder',
            ],
            'default_excluded_resource_groups' => [
                [
                    'key' => 'geography_reference_catalogs',
                    'resource_keys' => ['address-countries'],
                    'rule' => 'Keep read-only geography reference catalogs out of parity expansion by default unless there is an explicit integration need.',
                ],
                [
                    'key' => 'engagement_read_models',
                    'resource_keys' => [
                        'bookmark-collections',
                        'bookmarks',
                        'follows',
                        'reactions',
                        'reminders',
                        'responses',
                        'subscriptions',
                    ],
                    'rule' => 'Keep package-owned engagement read models panel-led by default; only expose them when there is a concrete product workflow that needs parity.',
                ],
                [
                    'key' => 'communication_delivery_ops',
                    'resource_keys' => [
                        'communications',
                        'communication-batches',
                        'communication-deliveries',
                        'communication-preferences',
                        'communication-suppressions',
                        'communication-templates',
                        'communication-threads',
                    ],
                    'rule' => 'Keep communication delivery and template operations out of generic parity expansion unless a user-facing workflow requires them.',
                ],
                [
                    'key' => 'system_and_admin_ops',
                    'resource_keys' => [
                        'ai-model-pricings',
                        'ai-usage-logs',
                        'audits',
                        'users',
                        'slug-redirects',
                        'roles',
                        'permissions',
                        'tracked-properties',
                        'signal-goals',
                        'signal-segments',
                        'signal-interaction-rules',
                        'saved-signal-reports',
                        'signal-alert-rules',
                        'signal-alert-logs',
                    ],
                    'rule' => 'Keep these panel-only by default; only expose them with an explicit product and security decision.',
                ],
            ],
            'maintenance_triggers' => [
                'Filament panel resource registration changes',
                'Admin or member mutation whitelist changes',
                'Workflow API controller or contract changes',
                'routes/api.php changes',
                'routes/ai.php changes',
                'MCP schema or file-normalization behavior changes',
            ],
            'same_change_set_artifacts' => [
                'docs/ilmu360_api_mcp_filament_crud_comparison.md',
                'docs/ilmu360_api_mcp_filament_crud_comparison.json',
                'tests/Unit/CrudComparisonDocsTest.php',
            ],
            'change_checklist' => [
                'Does the admin panel surface change?',
                'Does the admin API surface change?',
                'Does the admin MCP surface change?',
                'Does the Ahli panel surface change?',
                'Does the member MCP surface change?',
                'Does the public or authenticated workflow API surface change?',
                'If a surface does not change, is the gap intentional and documented?',
            ],
        ];
    }
}
