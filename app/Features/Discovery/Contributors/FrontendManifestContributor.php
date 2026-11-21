<?php

declare(strict_types=1);

namespace App\Features\Discovery\Contributors;

use App\Features\GitHubIssues\Api\GitHubIssueReporterContract;
use App\Features\ShareTracking\Api\ShareTrackingContract;
use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\SurfaceSyncPolicy;
use App\Platform\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Platform\Documentation\DocumentationLibrary;

final readonly class FrontendManifestContributor implements FrontendFormContractContributor
{
    public function __construct(
        private ApiDocumentationUrlResolver $urlResolver,
        private DocumentationLibrary $documentationLibrary,
        private ShareTrackingContract $shareTracking,
        private GitHubIssueReporterContract $gitHubIssueReporter,
    ) {}

    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::MANIFEST;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(?User $user): array
    {
        return [
            'version' => '2026-04-22',
            'docs' => [
                'ui' => $this->urlResolver->docsUrl(),
                'openapi' => $this->urlResolver->docsJsonUrl(),
                'api_base' => $this->urlResolver->apiBaseUrl(),
                'catalog_endpoint' => $this->documentationLibrary->apiCatalogEndpoint(),
                'document_endpoint_template' => $this->documentationLibrary->apiDocumentEndpointTemplate(),
                'library' => $this->documentationLibrary->apiLibrary(),
            ],
            'surface_sync' => SurfaceSyncPolicy::manifest(),
            'routing_surfaces' => [
                'public' => [
                    'base_path' => '/api/v1',
                    'record_scope' => 'Public records follow each resource visibility contract; references require published_at and verified or pending status.',
                    'manifest_endpoint' => route('api.client.manifest'),
                    'write_contract_family' => 'GET /forms/*',
                ],
                'admin' => [
                    'base_path' => '/api/v1/admin',
                    'record_scope' => 'All accessible records, including inactive, pending, and rejected records.',
                    'manifest_endpoint' => route('api.admin.manifest'),
                    'write_contract_family' => 'GET /admin/{resourceKey}/schema',
                ],
            ],
            'ai_quickstart' => [
                'read_order' => [
                    [
                        'step' => 1,
                        'action' => 'Fetch the machine-readable OpenAPI document.',
                        'endpoint' => $this->urlResolver->docsJsonUrl(),
                    ],
                    [
                        'step' => 2,
                        'action' => 'Discover public client workflows and endpoint templates.',
                        'endpoint' => route('api.client.manifest'),
                    ],
                    [
                        'step' => 3,
                        'action' => 'Before any public write, fetch the exact public form contract.',
                        'endpoint_family' => 'GET /forms/*',
                    ],
                    [
                        'step' => 4,
                        'action' => 'Before any admin write, fetch the admin resource manifest then the exact resource schema.',
                        'endpoint' => route('api.admin.manifest'),
                    ],
                ],
                'decision_points' => [
                    [
                        'need' => 'Public search, public detail pages, and client-parity reads.',
                        'use_surface' => 'public',
                    ],
                    [
                        'need' => 'Admin search, inactive or pending records, and schema-driven writes.',
                        'use_surface' => 'admin',
                    ],
                ],
            ],
            'rules' => [
                'Treat error.code as the machine-readable failure type.',
                'Use meta.request_id for request tracing and support handoff.',
                'Use UTC timestamps, but send date-only filters in the user local calendar together with timezone context.',
                'Use the admin record route_key returned by admin collection or detail payloads for record-specific schema and mutation paths.',
                'Fetch the exact form or schema contract before sending write payloads.',
            ],
            'catalogs' => [
                'countries' => route('api.client.catalogs.countries'),
                'states' => route('api.client.catalogs.states'),
                'cities' => route('api.client.catalogs.cities'),
                'administrative_district' => route('api.client.catalogs.administrative-districts'),
                'administrative_subdivision' => route('api.client.catalogs.administrative-subdivisions'),
                'languages' => route('api.client.catalogs.languages'),
                'submit_persons' => route('api.client.catalogs.submit-persons'),
                'submit_institutions' => route('api.client.catalogs.submit-institutions'),
                'venues' => route('api.client.catalogs.venues'),
                'references' => route('api.client.catalogs.references'),
                'taxonomy_terms_template' => route('api.client.catalogs.taxonomy-terms', ['type' => 'type'], false),
                'prayer_institutions' => $user instanceof User ? route('api.client.catalogs.prayer-institutions') : null,
                'institution_roles' => $user instanceof User ? route('api.client.catalogs.institution-roles') : null,
            ],
            'flows' => [
                'search' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.search.index'),
                    'auth_required' => false,
                ],
                'institutions_index' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.institutions.index'),
                    'auth_required' => false,
                ],
                'institutions_near' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.institutions.near'),
                    'auth_required' => false,
                    'supports_near' => true,
                    'near_format' => 'lat,lng',
                    'radius_parameter' => 'radius_km',
                ],
                'institutions_show' => [
                    'method' => 'GET',
                    'endpoint_template' => route('api.client.institutions.show', ['institutionKey' => 'subject'], false),
                    'auth_required' => false,
                ],
                'share' => [
                    'payload_method' => 'GET',
                    'payload_endpoint' => route('api.client.share.payload'),
                    'track_method' => 'POST',
                    'track_endpoint' => route('api.client.share.track'),
                    'auth_required' => false,
                    'payload_bearer_auth_optional' => true,
                    'track_bearer_auth_optional' => true,
                    'authenticated_tracking_required' => false,
                    'origins' => $this->shareTracking->supportedOrigins(),
                    'channels' => $this->shareTracking->supportedChannels(),
                    'copy_link_channel' => 'copy_link',
                    'native_share_channel' => 'native_share',
                ],
                'mobile_telemetry' => [
                    'method' => 'POST',
                    'endpoint' => route('api.client.mobile-telemetry.store'),
                    'schema_endpoint' => route('api.client.forms.mobile-telemetry'),
                    'auth_required' => false,
                    'bearer_auth_optional' => true,
                    'native_only' => true,
                    'intended_clients' => ['ios', 'ipados', 'android'],
                    'required_headers' => ['X-Ilmu360-Client-Origin'],
                ],
                'share_analytics' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.share.analytics'),
                    'link_endpoint_template' => route('api.client.share.analytics.links.show', ['link' => 'link'], false),
                    'auth_required' => true,
                    'filters' => [
                        'type' => ['all', 'event', 'institution', 'person', 'series', 'reference', 'search', 'page'],
                        'sort' => ['recent', 'visits', 'signups', 'registrations', 'checkins', 'submissions'],
                        'status' => ['all', 'active', 'inactive'],
                        'outcome' => ['all', 'signup', 'event_registration', 'event_checkin', 'event_submission', 'event_save', 'event_going', 'institution_follow', 'person_follow', 'series_follow', 'reference_follow', 'saved_search_created'],
                    ],
                ],
                'persons_index' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.persons.index'),
                    'auth_required' => false,
                ],
                'persons_show' => [
                    'method' => 'GET',
                    'endpoint_template' => route('api.client.persons.show', ['personKey' => 'subject'], false),
                    'auth_required' => false,
                ],
                'inspirations_random' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.inspirations.random'),
                    'auth_required' => false,
                ],
                'venues_show' => [
                    'method' => 'GET',
                    'endpoint_template' => route('api.client.venues.show', ['venueKey' => 'subject'], false),
                    'auth_required' => false,
                ],
                'references_show' => [
                    'method' => 'GET',
                    'endpoint_template' => route('api.client.references.show', ['referenceKey' => 'subject'], false),
                    'auth_required' => false,
                ],
                'series_show' => [
                    'method' => 'GET',
                    'endpoint_template' => route('api.client.series.show', ['series' => 'subject'], false),
                    'auth_required' => false,
                ],
                'submit_event' => [
                    'method' => 'POST',
                    'endpoint' => route('api.client.submit-event.store'),
                    'auth_required' => false,
                    'schema_endpoint' => route('api.client.forms.submit-event'),
                ],
                'submit_institution' => [
                    'method' => 'POST',
                    'endpoint' => route('api.client.contributions.institutions.store'),
                    'auth_required' => true,
                    'schema_endpoint' => route('api.client.forms.contributions.institutions'),
                ],
                'submit_person' => [
                    'method' => 'POST',
                    'endpoint' => route('api.client.contributions.persons.store'),
                    'auth_required' => true,
                    'schema_endpoint' => route('api.client.forms.contributions.persons'),
                ],
                'contribution_update' => [
                    'method' => 'POST',
                    'endpoint_template' => route('api.client.contributions.suggest.store', [
                        'subjectType' => 'subjectType',
                        'subject' => 'subject',
                    ], false),
                    'schema_endpoint_template' => route('api.client.forms.contributions.suggest', [
                        'subjectType' => 'subjectType',
                        'subject' => 'subject',
                    ], false),
                    'auth_required' => true,
                ],
                'contributions_index' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.contributions.index'),
                    'auth_required' => true,
                ],
                'membership_application' => [
                    'method' => 'POST',
                    'endpoint_template' => route('api.client.membership-applications.store', [
                        'subjectType' => 'subjectType',
                        'subject' => 'subject',
                    ], false),
                    'schema_endpoint_template' => route('api.client.forms.membership-applications', [
                        'subjectType' => 'subjectType',
                    ], false),
                    'auth_required' => true,
                ],
                'membership_applications_index' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.membership-applications.index'),
                    'auth_required' => true,
                ],
                'report' => [
                    'method' => 'POST',
                    'endpoint' => route('api.reports.store'),
                    'auth_required' => true,
                ],
                'account_settings' => [
                    'method' => 'PUT',
                    'endpoint' => route('api.client.account-settings.update'),
                    'auth_required' => true,
                ],
                'mcp_tokens' => [
                    'index_endpoint' => route('api.client.account-settings.mcp-tokens.index'),
                    'store_endpoint' => route('api.client.account-settings.mcp-tokens.store'),
                    'destroy_endpoint_template' => route('api.client.account-settings.mcp-tokens.destroy', ['tokenId' => 'token'], false),
                    'auth_required' => true,
                ],
                'advanced_event' => [
                    'method' => 'POST',
                    'endpoint' => route('api.client.advanced-events.store'),
                    'auth_required' => true,
                    'schema_endpoint' => route('api.client.forms.advanced-events'),
                ],
                'institution_workspace' => [
                    'method' => 'GET',
                    'endpoint' => route('api.client.institution-workspace.show'),
                    'auth_required' => true,
                ],
                'follow' => [
                    'state_method' => 'GET',
                    'state_endpoint_template' => route('api.client.follows.show', ['type' => 'type', 'subject' => 'subject'], false),
                    'follow_method' => 'POST',
                    'follow_endpoint_template' => route('api.client.follows.store', ['type' => 'type', 'subject' => 'subject'], false),
                    'unfollow_method' => 'DELETE',
                    'unfollow_endpoint_template' => route('api.client.follows.destroy', ['type' => 'type', 'subject' => 'subject'], false),
                    'auth_required' => true,
                ],
                ...($this->gitHubIssueReporter->isConfigured() ? [
                    'github_issue_report' => [
                        'method' => 'POST',
                        'endpoint' => route('api.client.github-issues.store'),
                        'schema_endpoint' => route('api.client.forms.github-issue-report'),
                        'auth_required' => true,
                    ],
                ] : []),
            ],
            'auth_context' => [
                'authenticated' => $user instanceof User,
                'requires_bearer_token_for_mutations' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context): array
    {
        $user = $context['user'] ?? null;

        return $this->manifest($user instanceof User ? $user : null);
    }
}
