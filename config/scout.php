<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | This option controls the default search connection that gets used while
    | using Laravel Scout. This connection is used when syncing all models
    | to the search service. You should adjust this based on your needs.
    |
    | Supported: "algolia", "meilisearch", "typesense",
    |            "database", "collection", "null"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'collection'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Here you may specify a prefix that will be applied to all search index
    | names used by Scout. This prefix may be useful if you have multiple
    | "tenants" or applications sharing the same search infrastructure.
    |
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | This option allows you to control if the operations that sync your data
    | with your search engines are queued. When this is set to "true" then
    | all automatic data syncing will get queued for better performance.
    |
    */

    'queue' => env('SCOUT_QUEUE', false)
        ? [
            'connection' => env('SCOUT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
            'queue' => env('SCOUT_QUEUE_NAME'),
        ]
        : false,

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    |
    | This configuration option determines if your data will only be synced
    | with your search indexes after every open database transaction has
    | been committed, thus preventing any discarded data from syncing.
    |
    */

    'after_commit' => env('SCOUT_AFTER_COMMIT', true),

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    |
    | These options allow you to control the maximum chunk size when you are
    | mass importing data into the search engine. This allows you to fine
    | tune each of these chunk sizes based on the power of the servers.
    |
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | This option allows to control whether to keep soft deleted records in
    | the search indexes. Maintaining soft deleted records can be useful
    | if your application still needs to search for the records later.
    |
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    |
    | This option allows you to control whether to notify the search engine
    | of the user performing the search. This is sometimes useful if the
    | engine supports any analytics based on this application's users.
    |
    | Supported engines: "algolia"
    |
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Algolia Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Algolia settings. Algolia is a cloud hosted
    | search engine which works great with Scout out of the box. Just plug
    | in your application ID and admin API key to get started searching.
    |
    */

    'algolia' => [
        'id' => env('ALGOLIA_APP_ID', ''),
        'secret' => env('ALGOLIA_SECRET', ''),
        'index-settings' => [
            // 'users' => [
            //     'searchableAttributes' => ['id', 'name', 'email'],
            //     'attributesForFaceting'=> ['filterOnly(email)'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Meilisearch settings. Meilisearch is an open
    | source search engine with minimal configuration. Below, you can state
    | the host and key information for your own Meilisearch installation.
    |
    | See: https://www.meilisearch.com/docs/learn/configuration/instance_options#all-instance-options
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            // 'users' => [
            //     'filterableAttributes'=> ['id', 'name', 'email'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typesense Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Typesense settings. Typesense is an open
    | source search engine using minimal configuration. Below, you will
    | state the host, key, and schema configuration for the instance.
    |
    */

    'typesense' => [
        'client-settings' => [
            'api_key' => env('TYPESENSE_API_KEY', 'xyz'),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'path' => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'nearest_node' => [
                'host' => env('TYPESENSE_HOST', 'localhost'),
                'port' => env('TYPESENSE_PORT', '8108'),
                'path' => env('TYPESENSE_PATH', ''),
                'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
            ],
            'connection_timeout_seconds' => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            'healthcheck_interval_seconds' => env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 30),
            'num_retries' => env('TYPESENSE_NUM_RETRIES', 3),
            'retry_interval_seconds' => env('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
        ],
        // 'max_total_results' => env('TYPESENSE_MAX_TOTAL_RESULTS', 1000),
        'model-settings' => [
            Event::class => [
                'collection-schema' => [
                    'fields' => [
                        ['name' => 'id', 'type' => 'string'],
                        ['name' => 'title', 'type' => 'string'],
                        ['name' => 'description', 'type' => 'string', 'optional' => true],
                        ['name' => 'slug', 'type' => 'string'],
                        ['name' => 'speaker_names', 'type' => 'string', 'optional' => true],
                        ['name' => 'institution_name', 'type' => 'string', 'optional' => true],
                        ['name' => 'venue_name', 'type' => 'string', 'optional' => true],
                        ['name' => 'state_id', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'state_name', 'type' => 'string', 'optional' => true],
                        ['name' => 'district_id', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'subdistrict_id', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'language_codes', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'event_type', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'age_group', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
                        ['name' => 'status', 'type' => 'string', 'facet' => true],
                        ['name' => 'visibility', 'type' => 'string', 'facet' => true],
                        ['name' => 'topic_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'domain_tag_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'source_tag_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'issue_tag_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'reference_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'speaker_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'key_person_roles', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'key_person_speaker_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'person_in_charge_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'person_in_charge_names', 'type' => 'string', 'optional' => true],
                        ['name' => 'moderator_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'imam_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'khatib_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'bilal_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'starts_at', 'type' => 'int64'],
                        ['name' => 'ends_at', 'type' => 'int64', 'optional' => true],
                        ['name' => 'saves_count', 'type' => 'int32', 'optional' => true],
                        ['name' => 'registrations_count', 'type' => 'int32', 'optional' => true],
                        ['name' => 'location', 'type' => 'geopoint', 'optional' => true],
                    ],
                    'default_sorting_field' => 'starts_at',
                ],
                'search-parameters' => [
                    'query_by' => 'title,description,speaker_names,institution_name,venue_name',
                ],
            ],
            Speaker::class => [
                'collection-schema' => [
                    'fields' => [
                        ['name' => 'id', 'type' => 'string'],
                        ['name' => 'name', 'type' => 'string'],
                        ['name' => 'formatted_name', 'type' => 'string'],
                        ['name' => 'search_text', 'type' => 'string'],
                        ['name' => 'job_title', 'type' => 'string', 'optional' => true],
                        ['name' => 'slug', 'type' => 'string'],
                        ['name' => 'status', 'type' => 'string', 'facet' => true],
                        ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
                        ['name' => 'gender', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'country_id', 'type' => 'int32', 'optional' => true, 'facet' => true],
                        ['name' => 'state_id', 'type' => 'int32', 'optional' => true, 'facet' => true],
                        ['name' => 'district_id', 'type' => 'int32', 'optional' => true, 'facet' => true],
                        ['name' => 'subdistrict_id', 'type' => 'int32', 'optional' => true, 'facet' => true],
                        ['name' => 'updated_at', 'type' => 'int64'],
                    ],
                    'default_sorting_field' => 'updated_at',
                ],
                'search-parameters' => [
                    'query_by' => 'formatted_name,search_text,name,job_title',
                ],
            ],
            Institution::class => [
                'collection-schema' => [
                    'fields' => [
                        ['name' => 'id', 'type' => 'string'],
                        ['name' => 'type', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'name', 'type' => 'string'],
                        ['name' => 'display_name', 'type' => 'string'],
                        ['name' => 'nickname', 'type' => 'string', 'optional' => true],
                        ['name' => 'description', 'type' => 'string', 'optional' => true],
                        ['name' => 'search_text', 'type' => 'string'],
                        ['name' => 'slug', 'type' => 'string'],
                        ['name' => 'status', 'type' => 'string', 'facet' => true],
                        ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
                        ['name' => 'country_id', 'type' => 'int32', 'optional' => true, 'facet' => true],
                        ['name' => 'state_id', 'type' => 'int32', 'optional' => true, 'facet' => true],
                        ['name' => 'district_id', 'type' => 'int32', 'optional' => true, 'facet' => true],
                        ['name' => 'subdistrict_id', 'type' => 'int32', 'optional' => true, 'facet' => true],
                        ['name' => 'updated_at', 'type' => 'int64'],
                    ],
                    'default_sorting_field' => 'updated_at',
                ],
                'search-parameters' => [
                    'query_by' => 'display_name,name,nickname,description,search_text',
                ],
            ],
            Reference::class => [
                'collection-schema' => [
                    'fields' => [
                        ['name' => 'id', 'type' => 'string'],
                        ['name' => 'title', 'type' => 'string'],
                        ['name' => 'author', 'type' => 'string', 'optional' => true],
                        ['name' => 'type', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'publication_year', 'type' => 'int32', 'optional' => true],
                        ['name' => 'publisher', 'type' => 'string', 'optional' => true],
                        ['name' => 'description', 'type' => 'string', 'optional' => true],
                        ['name' => 'search_text', 'type' => 'string'],
                        ['name' => 'slug', 'type' => 'string'],
                        ['name' => 'status', 'type' => 'string', 'facet' => true],
                        ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
                        ['name' => 'updated_at', 'type' => 'int64'],
                    ],
                    'default_sorting_field' => 'updated_at',
                ],
                'search-parameters' => [
                    'query_by' => 'title,author,publisher,description,search_text',
                ],
            ],
        ],
        'import_action' => env('TYPESENSE_IMPORT_ACTION', 'upsert'),
    ],

];
