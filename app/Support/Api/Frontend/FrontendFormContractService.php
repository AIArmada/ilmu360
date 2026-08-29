<?php

namespace App\Support\Api\Frontend;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Persons\Enums\Gender;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\InstitutionType;
use App\Enums\MemberSubjectType;
use App\Enums\RegistrationScope;
use App\Models\User;
use App\Services\GitHub\GitHubIssueReporter;
use App\Services\ShareTrackingService;
use App\Support\Api\SurfaceSyncPolicy;
use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Support\Documentation\DocumentationLibrary;
use App\Support\GitHub\GitHubIssueReportContract;
use App\Support\Location\GooglePlacesConfiguration;
use App\Support\Mcp\McpTokenManager;

class FrontendFormContractService
{
    public function __construct(
        private readonly FrontendCatalogService $catalogService,
        private readonly ApiDocumentationUrlResolver $urlResolver,
        private readonly DocumentationLibrary $documentationLibrary,
        private readonly McpTokenManager $mcpTokenManager,
        private readonly GitHubIssueReporter $gitHubIssueReporter,
        private readonly ShareTrackingService $shareTrackingService,
    ) {}

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
                'tags_template' => route('api.client.catalogs.tags', ['type' => 'type'], false),
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
                    'origins' => $this->shareTrackingService->supportedOrigins(),
                    'channels' => $this->shareTrackingService->supportedChannels(),
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
                'membership_claim' => [
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
                ...($this->supportsGitHubIssueReporting() ? [
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
     * @return array<string, mixed>
     */
    public function submitEvent(?User $user): array
    {
        $submissionCountryIds = AddressCountry::query()
            ->orderBy('name')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return [
            'flow' => 'submit_event',
            'method' => 'POST',
            'endpoint' => route('api.client.submit-event.store'),
            'auth_required' => false,
            'captcha_required_when_turnstile_enabled' => true,
            'defaults' => [
                'event_id' => null,
                'scoped_institution_id' => null,
                'submitter_name' => $user?->name,
                'submitter_email' => $user?->email,
                'children_allowed' => true,
                'gender' => EventGenderRestriction::All->value,
                'age_group' => [EventAgeGroup::AllAges->value],
                'languages' => [101],
                'event_format' => EventFormat::Physical->value,
                'visibility' => EventVisibility::Public->value,
                'primary_organizer_id' => null,
                'location_same_as_institution' => true,
                'location_type' => 'institution',
                'is_muslim_only' => false,
                'other_key_people' => [],
                'captcha_token' => null,
                'submission_country_id' => null,
            ],
            'fields' => [
                $this->field('title', 'string', required: true, maxLength: 255),
                $this->field('description', 'rich_text', required: false),
                $this->field('event_id', 'uuid', required: false),
                $this->field('scoped_institution_id', 'uuid', required: false),
                $this->field('event_category_ids', 'array<uuid>', required: true, allowedValues: array_keys(app(EventCategoryCatalog::class)->options())),
                $this->field('event_date', 'date', required: true),
                $this->field('prayer_time', 'string', required: true, allowedValues: $this->enumValues(EventPrayerTime::class)),
                $this->field('custom_time', 'time', required: false),
                $this->field('end_time', 'time', required: false),
                $this->field('event_format', 'string', required: true, default: EventFormat::Physical->value, allowedValues: $this->enumValues(EventFormat::class)),
                $this->field('visibility', 'string', required: true, default: EventVisibility::Public->value, allowedValues: $this->enumValues(EventVisibility::class)),
                $this->field('event_url', 'url', required: false),
                $this->field('live_url', 'url', required: false),
                $this->field('gender', 'string', required: true, default: EventGenderRestriction::All->value, allowedValues: $this->enumValues(EventGenderRestriction::class)),
                $this->field('age_group', 'array<string>', required: true, default: [EventAgeGroup::AllAges->value], allowedValues: $this->enumValues(EventAgeGroup::class)),
                $this->field('children_allowed', 'boolean', required: false, default: true),
                $this->field('is_muslim_only', 'boolean', required: false, default: false),
                $this->field('languages', 'array<int>', required: true, default: [101], catalog: route('api.client.catalogs.languages')),
                $this->field('domain_tags', 'array<string>', required: false, catalog: route('api.client.catalogs.tags', ['type' => EventTaxonomyCode::Domain->value])),
                $this->field('discipline_tags', 'array<string>', required: false, catalog: route('api.client.catalogs.tags', ['type' => EventTaxonomyCode::Discipline->value])),
                $this->field('source_tags', 'array<string>', required: false, catalog: route('api.client.catalogs.tags', ['type' => EventTaxonomyCode::Source->value])),
                $this->field('issue_tags', 'array<string>', required: false, catalog: route('api.client.catalogs.tags', ['type' => EventTaxonomyCode::Issue->value])),
                $this->field('references', 'array<string>', required: false, catalog: route('api.client.catalogs.references')),
                $this->field('primary_organizer_id', 'uuid', required: true, meta: [
                    'catalogs' => [
                        'institution' => route('api.client.catalogs.submit-institutions'),
                        'person' => route('api.client.catalogs.submit-persons'),
                    ],
                ]),
                $this->field('location_same_as_institution', 'boolean', required: false, default: true),
                $this->field('location_type', 'string', required: false, default: 'institution', allowedValues: ['institution', 'venue']),
                $this->field('location_institution_id', 'uuid', required: false, catalog: route('api.client.catalogs.submit-institutions')),
                $this->field('location_venue_id', 'uuid', required: false, catalog: route('api.client.catalogs.venues')),
                $this->field('space_id', 'uuid', required: false, catalog: route('api.client.catalogs.spaces')),
                $this->field('persons', 'array<string>', required: false, catalog: route('api.client.catalogs.submit-persons')),
                $this->field('other_key_people', 'array<object>', required: false),
                $this->field('submission_country_id', 'uuid', required: true, allowedValues: $submissionCountryIds),
                $this->field('submitter_name', 'string', required: ! $user instanceof User, maxLength: 255),
                $this->field('submitter_email', 'email', required: false),
                $this->field('submitter_phone', 'string', required: false),
                $this->field('notes', 'string', required: false, maxLength: 1000),
                $this->field('captcha_token', 'string', required: false),
                $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: [
                    'required_aspect_ratio' => '16:9',
                    'media_role' => 'website_app_cover',
                ]),
                $this->field('poster', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: [
                    'required_aspect_ratio' => '3:4',
                    'media_role' => 'external_distribution_poster',
                ]),
                $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 10),
            ],
            'conditional_rules' => [
                ['field' => 'location_type', 'required_when' => ['location_same_as_institution' => [false]]],
                ['field' => 'location_institution_id', 'required_when' => ['location_type' => ['institution']]],
                ['field' => 'location_venue_id', 'required_when' => ['location_type' => ['venue']]],
                ['field' => 'submitter_email', 'required_when_missing' => ['submitter_phone']],
                ['field' => 'submitter_phone', 'required_when_missing' => ['submitter_email']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitInstitution(): array
    {
        return [
            'flow' => 'submit_institution',
            'method' => 'POST',
            'endpoint' => route('api.client.contributions.institutions.store'),
            'auth_required' => true,
            'defaults' => [
                'type' => InstitutionType::Masjid->value,
                'address' => [
                    'country_id' => null,
                    'area_assignments' => [],
                    'line1' => null,
                    'line2' => null,
                    'postcode' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'google_maps_url' => null,
                    'provider_place_id' => null,
                    'google_maps_normalization_enabled' => true,
                    'google_maps_remote_lookup_enabled' => GooglePlacesConfiguration::isEnabled(),
                ],
            ],
            'fields' => [
                $this->field('name', 'string', required: true, maxLength: 255),
                $this->field('middle_name', 'string', required: false, maxLength: 100),
                $this->field('family_name', 'string', required: false, maxLength: 100),
                $this->field('names', 'array', required: false, meta: [
                    'item_schema' => [
                        'type' => 'object',
                        'fields' => [
                            ['name' => 'full_name', 'type' => 'string', 'required' => true, 'max_length' => 255],
                            ['name' => 'name_type', 'type' => 'string', 'required' => false, 'allowed_values' => ['official', 'nickname', 'abbreviation', 'local', 'historical']],
                            ['name' => 'language_code', 'type' => 'string', 'required' => false],
                            ['name' => 'is_primary', 'type' => 'boolean', 'required' => false],
                        ],
                    ],
                ]),
                $this->field('type', 'string', required: true, default: InstitutionType::Masjid->value, allowedValues: $this->enumValues(InstitutionType::class)),
                $this->field('description', 'rich_text', required: false),
                $this->field('address', 'object', required: true),
                $this->field('address.country_id', 'uuid', required: true, catalog: route('api.client.catalogs.countries')),
                $this->field('contactMethods', 'array<object>', required: false),
                $this->field('social_media', 'array<object>', required: false),
                $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
                $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            ],
            'conditional_rules' => [
                ['field' => 'address.google_maps_url', 'required' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitPerson(): array
    {
        return [
            'flow' => 'submit_person',
            'method' => 'POST',
            'endpoint' => route('api.client.contributions.persons.store'),
            'auth_required' => true,
            'defaults' => [
                'gender' => Gender::Male->value,
                'address' => [
                    'country_id' => null,
                    'area_assignments' => [],
                ],
            ],
            'fields' => [
                $this->field('name', 'string', required: true, maxLength: 255),
                $this->field('gender', 'string', required: true, default: Gender::Male->value, allowedValues: $this->enumValues(Gender::class)),
                $this->field('bio', 'rich_text', required: false),
                $this->field('institution_id', 'uuid', required: false, catalog: route('api.client.catalogs.submit-institutions')),
                $this->field('institution_position', 'string', required: false, maxLength: 255),
                $this->field('address', 'object', required: true),
                $this->field('address.country_id', 'uuid', required: true, catalog: route('api.client.catalogs.countries')),
                $this->field('address.area_assignments', 'array<object>', required: false),
                $this->field('language_ids', 'array<int>', required: false, catalog: route('api.client.catalogs.languages')),
                $this->field('contactMethods', 'array<object>', required: false),
                $this->field('social_media', 'array<object>', required: false),
                $this->field('avatar', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
                $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
                $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            ],
            'conditional_rules' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mobileTelemetry(): array
    {
        return [
            'flow' => 'mobile_telemetry',
            'method' => 'POST',
            'endpoint' => route('api.client.mobile-telemetry.store'),
            'auth_required' => false,
            'bearer_auth_optional' => true,
            'native_only' => true,
            'intended_clients' => ['ios', 'ipados', 'android'],
            'required_headers' => [
                [
                    'name' => 'X-Ilmu360-Client-Origin',
                    'required' => true,
                    'allowed_values' => ['iosapp', 'ipadosapp', 'androidapp'],
                    'description' => 'Required native client hint. Mobile web browsing should keep using the browser tracker instead of this endpoint.',
                ],
                [
                    'name' => 'X-Ilmu360-Client-Name',
                    'required' => false,
                ],
                [
                    'name' => 'X-Ilmu360-Client-Version',
                    'required' => false,
                ],
                [
                    'name' => 'X-Ilmu360-Client-Build',
                    'required' => false,
                ],
            ],
            'defaults' => [
                'anonymous_id' => null,
                'session_identifier' => null,
                'session_started_at' => null,
                'events' => [],
            ],
            'fields' => [
                $this->field('anonymous_id', 'string', required: false, maxLength: 120),
                $this->field('session_identifier', 'string', required: false, maxLength: 120),
                $this->field('session_started_at', 'datetime', required: false),
                $this->field('events', 'array<object>', required: true),
            ],
            'event_fields' => [
                $this->field('event_name', 'string', required: true, maxLength: 120),
                $this->field('event_category', 'string', required: false, maxLength: 80),
                $this->field('occurred_at', 'datetime', required: false),
                $this->field('path', 'string', required: false, maxLength: 2048),
                $this->field('url', 'string', required: false, maxLength: 2048),
                $this->field('referrer', 'string', required: false, maxLength: 2048),
                $this->field('screen_name', 'string', required: false, maxLength: 120),
                $this->field('previous_screen_name', 'string', required: false, maxLength: 120),
                $this->field('component', 'string', required: false, maxLength: 120),
                $this->field('action', 'string', required: false, maxLength: 120),
                $this->field('utm_source', 'string', required: false, maxLength: 120),
                $this->field('utm_medium', 'string', required: false, maxLength: 120),
                $this->field('utm_campaign', 'string', required: false, maxLength: 120),
                $this->field('utm_content', 'string', required: false, maxLength: 120),
                $this->field('utm_term', 'string', required: false, maxLength: 120),
                $this->field('properties', 'object', required: false),
            ],
            'conditional_rules' => [
                ['field' => 'anonymous_id', 'required_when_missing' => ['session_identifier'], 'unless_authenticated' => true],
                ['field' => 'session_identifier', 'required_when_missing' => ['anonymous_id'], 'unless_authenticated' => true],
            ],
            'notes' => [
                'This endpoint is reserved for real native app sessions on iOS, iPadOS, and Android.',
                'Do not use this endpoint for mobile web page views; browser sessions should keep using the web signals tracker.',
                'Batch up to 50 events per request and send stable anonymous/session identifiers for guest sessions.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function membershipClaim(MemberSubjectType $subjectType): array
    {
        return [
            'flow' => 'membership_claim',
            'method' => 'POST',
            'endpoint_template' => route('api.client.membership-applications.store', [
                'subjectType' => $subjectType->publicRouteSegment(),
                'subject' => 'subject',
            ], false),
            'auth_required' => true,
            'fields' => [
                $this->field('justification', 'string', required: true, maxLength: 2000),
                $this->field('evidence', 'array<file>', required: true, acceptedMimeTypes: $this->evidenceMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 8),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return [
            'flow' => 'report',
            'method' => 'POST',
            'endpoint' => route('api.reports.store'),
            'auth_required' => true,
            'fields' => [
                $this->field('entity_type', 'string', required: true),
                $this->field('entity_id', 'uuid', required: true),
                $this->field('category', 'string', required: true),
                $this->field('description', 'string', required: false, maxLength: 2000),
                $this->field('evidence', 'array<file>', required: false, acceptedMimeTypes: $this->evidenceMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 8),
            ],
            'conditional_rules' => [
                ['field' => 'description', 'required_when' => ['category' => ['other']]],
            ],
        ];
    }

    public function supportsGitHubIssueReporting(): bool
    {
        return $this->gitHubIssueReporter->isConfigured();
    }

    /**
     * @return array<string, mixed>
     */
    public function githubIssueReport(): array
    {
        return [
            'flow' => 'github_issue_report',
            'method' => 'POST',
            'endpoint' => route('api.client.github-issues.store'),
            'auth_required' => true,
            'defaults' => GitHubIssueReportContract::defaults(),
            'fields' => GitHubIssueReportContract::fields(),
            'conditional_rules' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function accountSettings(User $user): array
    {
        $mcpServers = $this->mcpTokenManager->availableServers($user);

        return [
            'flow' => 'account_settings',
            'method' => 'PUT',
            'endpoint' => route('api.client.account-settings.update'),
            'show_endpoint' => route('api.client.account-settings.show'),
            'auth_required' => true,
            'defaults' => [
                'name' => $user->name,
                'email' => (string) ($user->email ?? ''),
                'phone' => (string) ($user->phone ?? ''),
                'timezone' => (string) ($user->timezone ?? ''),
                'daily_prayer_institution_id' => (string) ($user->daily_prayer_institution_id ?? ''),
                'friday_prayer_institution_id' => (string) ($user->friday_prayer_institution_id ?? ''),
            ],
            'fields' => [
                $this->field('name', 'string', required: true, maxLength: 255),
                $this->field('email', 'email', required: false, maxLength: 255),
                $this->field('phone', 'string', required: false),
                $this->field('timezone', 'timezone', required: false),
                $this->field('daily_prayer_institution_id', 'uuid', required: false, catalog: route('api.client.catalogs.prayer-institutions')),
                $this->field('friday_prayer_institution_id', 'uuid', required: false, catalog: route('api.client.catalogs.prayer-institutions')),
            ],
            'conditional_rules' => [
                ['field' => 'email', 'required_when_missing' => ['phone']],
                ['field' => 'phone', 'required_when_missing' => ['email']],
            ],
            'notification_settings_endpoint' => route('api.notification-settings.show'),
            'notification_update_endpoint' => route('api.notification-settings.update'),
            'mcp_tokens_endpoint' => route('api.client.account-settings.mcp-tokens.index'),
            'mcp_token_store_endpoint' => route('api.client.account-settings.mcp-tokens.store'),
            'mcp_token_revoke_endpoint_template' => route('api.client.account-settings.mcp-tokens.destroy', ['tokenId' => 'token'], false),
            'mcp_token_fields' => [
                $this->field('name', 'string', required: true, maxLength: 255),
                $this->field('server', 'string', required: true, allowedValues: array_keys($mcpServers)),
            ],
            'mcp_servers' => $mcpServers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function advancedEvent(User $user): array
    {
        $builderContext = $this->catalogService->advancedBuilderContext($user);

        return [
            'flow' => 'advanced_event',
            'method' => 'POST',
            'endpoint' => route('api.client.advanced-events.store'),
            'auth_required' => true,
            'defaults' => $builderContext['default_form'],
            'fields' => [
                $this->field('title', 'string', required: true, maxLength: 255),
                $this->field('description', 'string', required: false),
                $this->field('timezone', 'timezone', required: true, default: 'Asia/Kuala_Lumpur'),
                $this->field('program_starts_at', 'datetime', required: true),
                $this->field('program_ends_at', 'datetime', required: true),
                $this->field('primary_organizer_id', 'uuid', required: true),
                $this->field('location_institution_id', 'uuid', required: false),
                $this->field('default_event_category_ids', 'array<uuid>', required: true, allowedValues: array_keys(app(EventCategoryCatalog::class)->options())),
                $this->field('default_event_format', 'string', required: true, allowedValues: $this->enumValues(EventFormat::class)),
                $this->field('visibility', 'string', required: true, allowedValues: $this->enumValues(EventVisibility::class)),
                $this->field('registration_required', 'boolean', required: true),
                $this->field('registration_mode', 'string', required: true, allowedValues: $this->enumValues(RegistrationScope::class)),
            ],
            'options' => [
                'primary_organizer_options' => [
                    'institution' => $builderContext['institution_options'],
                    'person' => $builderContext['person_options'],
                ],
                'location_institution_options' => $builderContext['institution_options'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function institutionWorkspace(): array
    {
        return [
            'flow' => 'institution_workspace',
            'workspace_endpoint' => route('api.client.institution-workspace.show'),
            'member_add_endpoint_template' => route('api.client.institution-workspace.members.store', ['institutionId' => 'institution'], false),
            'member_update_endpoint_template' => route('api.client.institution-workspace.members.update', ['institutionId' => 'institution', 'memberId' => 'member'], false),
            'member_remove_endpoint_template' => route('api.client.institution-workspace.members.destroy', ['institutionId' => 'institution', 'memberId' => 'member'], false),
            'member_add_fields' => [
                $this->field('email', 'email', required: true),
                $this->field('role_id', 'string', required: true, catalog: route('api.client.catalogs.institution-roles')),
            ],
            'member_edit_fields' => [
                $this->field('role_id', 'string', required: true, catalog: route('api.client.catalogs.institution-roles')),
            ],
        ];
    }

    /**
     * @param  class-string<\BackedEnum>  $enumClass
     * @return list<string|int>
     */
    private function enumValues(string $enumClass): array
    {
        return array_map(static fn (\BackedEnum $enum): string|int => $enum->value, $enumClass::cases());
    }

    /**
     * @param  list<string|int>|null  $allowedValues
     * @param  list<string>|null  $acceptedMimeTypes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function field(
        string $name,
        string $type,
        bool $required,
        mixed $default = null,
        ?int $maxLength = null,
        ?array $allowedValues = null,
        ?string $catalog = null,
        ?array $acceptedMimeTypes = null,
        ?int $maxFileSizeKb = null,
        ?int $maxFiles = null,
        array $meta = [],
    ): array {
        return array_merge(
            array_filter([
                'name' => $name,
                'type' => $type,
                'required' => $required,
                'default' => $default,
                'max_length' => $maxLength,
                'allowed_values' => $allowedValues,
                'catalog' => $catalog,
                'accepted_mime_types' => $acceptedMimeTypes,
                'max_file_size_kb' => $maxFileSizeKb,
                'max_files' => $maxFiles,
            ], static fn (mixed $value): bool => $value !== null),
            array_filter($meta, static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @return list<string>
     */
    private function imageMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * @return list<string>
     */
    private function evidenceMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    }

    private function maxUploadSizeKb(): int
    {
        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }
}
