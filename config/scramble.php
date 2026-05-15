<?php

declare(strict_types=1);

return [
    /*
     * Your API path. By default, all routes starting with this path will be added to the docs.
     * If you need to change this behavior, you can add your custom routes resolver using `Scramble::routes()`.
     */
    'api_path' => env('SCRAMBLE_API_PATH', 'api/v1'),

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => env('SCRAMBLE_API_DOMAIN'),

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => env('SCRAMBLE_EXPORT_PATH', 'docs/openapi.json'),

    'info' => [
        /*
         * API version.
         */
        'version' => env('API_VERSION', 'v1'),

        /*
         * Description rendered on the home page of the API documentation (`/docs`).
         */
        'description' => <<<'MD'
    Canonical API documentation for ilmu360° client and platform integrations.

    Get an access token by calling POST /auth/login or POST /auth/register with a device_name, then send the returned access_token as Authorization: Bearer {token}. For integrations that should not store an account password, super admins can issue and revoke long-lived personal access tokens from Admin > Authz > User > API Access for the selected user.

    ROUTING SURFACES:

    Public routes (/api/v1/speakers, /api/v1/institutions, etc.) return only active and verified records (is_active=true AND status=verified).

    Admin routes (/api/v1/admin/speakers, etc.) return all records by default. Event collections expose explicit filters such as filter[status], filter[visibility], filter[event_format], filter[event_type], filter[timing_mode], and filter[prayer_reference]. Speaker collections expose filter[status], filter[is_active], and filter[has_events]. Date-aware admin resources also accept starts_after, starts_before, and starts_on_local_date.

    The same search= parameter on both surfaces returns different result sets by design.

    Collection endpoints clamp per_page to server-supported maxima. Public /events, /institutions, and /speakers currently cap at 50. Most authenticated collections and admin resource listings currently cap at 100.

    Do not send public contribution payloads to /admin endpoints and do not expect admin schemas from /forms endpoints.

    TIMEZONE:

    Raw API timestamp fields are stored and returned in UTC (ISO 8601 with Z suffix).

    Viewer-facing helper fields such as event timing_display and end_time_display are localized only when the request provides timezone context (authenticated user preference, X-Timezone, user_timezone, cookie, or session). Without timezone context, bare API requests fall back to UTC.

    Public event date-only filters use `filter[starts_after]`, `filter[starts_before]`, and `filter[starts_on_local_date]`. Admin resource date-only filters use top-level `starts_after`, `starts_before`, and `starts_on_local_date`. All of these are interpreted in the resolved request timezone and converted to UTC day boundaries before querying.

    For MYT today requests, send X-Timezone: Asia/Kuala_Lumpur with either public-event values such as filter[starts_after]=2026-04-12&filter[starts_before]=2026-04-12 or admin-resource values such as starts_after=2026-04-12&starts_before=2026-04-12.

    The server timezone is UTC. The web application commonly supplies Asia/Kuala_Lumpur as user timezone context, but API consumers should not assume MYT unless they send it explicitly.

    PUBLIC FLOWS:

    Public create flows currently exist for events, institutions, and speakers.

    Public update flows currently exist for events, institutions, speakers, and references via the contribution suggestion endpoints; those updates either apply immediately (mode=direct_edit) or create a review request (mode=review) depending on the caller's permissions.

    Public API support does not currently include creating references, venues, or series, and it does not currently include updating venues or series through the contribution endpoints.

    When you need required versus optional fields, defaults, catalog lookups, or conditional rules for a public mutation, fetch the corresponding GET /forms/* contract first.

    For public update suggestions specifically, fetch GET /forms/contributions/{subjectType}/{subject}/suggest first to get the current state, sparse editable fields, and direct-edit media capabilities.

    GET /catalogs/spaces returns only global spaces when institution_id is omitted, and returns global plus institution-linked spaces when institution_id is provided.

    GET /institution-workspace auto-selects the first accessible institution when institution_id is omitted and always returns selected_institution together with events_pagination and members_pagination metadata.

    Public institution, speaker, and submit-event writes must include an explicit country selection using the canonical *_country_id fields.

    Speaker create/update still forbids detailed street or map fields: address.line1, address.line2, address.postcode, address.lat, address.lng, address.google_maps_url, address.google_place_id, and address.waze_url return HTTP 422 on speaker contribution flows.

    ADMIN FLOWS:

    Admin create and update flows are schema-driven: discover writable resources with GET /admin/manifest, then fetch the exact contract with GET /admin/{resourceKey}/schema?operation=create or GET /admin/{resourceKey}/schema?operation=update&recordKey={recordKey}.

    The recordKey parameter should use the record route_key returned by the admin collection or record-detail responses. If you only have a public UUID-backed payload and route_key is unavailable, use the UUID id directly as recordKey.

    Admin PUT is not a partial update. Fields marked required in the schema must be sent on every update, not just on create.

    For speakers: name, gender, and status are always required on both create and update.

    For institutions: name, type, and status are always required on both create and update.

    The allow_public_event_submission field is only accepted on PUT (update), not on POST (create).

    For admin geography lookups, use the authenticated GET /admin/catalogs/* endpoints referenced by schema catalog metadata.

    Current admin write support includes events, institutions, speakers, references, venues, and subdistricts.

    Admin write support is limited to resources whose write_support.schema flag is true in the admin manifest.
    MD,
    ],

    /*
     * Customize Stoplight Elements UI
     */
    'ui' => [
        /*
         * Define the title of the documentation's website. App name is used when this config is `null`.
         */
        'title' => env('SCRAMBLE_UI_TITLE', env('APP_NAME').' API'),

        /*
         * Define the theme of the documentation. Available options are `light`, `dark`, and `system`.
         */
        'theme' => 'light',

        /*
         * Hide the `Try It` feature. Enabled by default.
         */
        'hide_try_it' => false,

        /*
         * Hide the schemas in the Table of Contents. Enabled by default.
         */
        'hide_schemas' => false,

        /*
         * URL to an image that displays as a small square logo next to the title, above the table of contents.
         */
        'logo' => '',

        /*
         * Use to fetch the credential policy for the Try It feature. Options are: omit, include (default), and same-origin
         */
        'try_it_credentials_policy' => 'include',

        /*
         * There are three layouts for Elements:
         * - sidebar - (Elements default) Three-column design with a sidebar that can be resized.
         * - responsive - Like sidebar, except at small screen sizes it collapses the sidebar into a drawer that can be toggled open.
         * - stacked - Everything in a single column, making integrations with existing websites that have their own sidebar or other columns already.
         */
        'layout' => 'responsive',
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
    ],

    'extensions' => [],
];
