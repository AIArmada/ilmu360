<?php

declare(strict_types=1);

return [
    'cache' => [
        'store' => env('DISCOVERY_CACHE_STORE'),
        'enabled' => (bool) env('DISCOVERY_CACHE_ENABLED', true),
        'ttls' => [
            'catalog' => 3600,
            'search_public' => 300,
            'default_schedule' => 60,
            'homepage_featured' => 120,
            'homepage_stats' => 300,
            'submission_options' => 900,
            'health_check' => 30,
            'sitemap' => 3600,
            'http_catalog_max_age' => 300,
            'http_catalog_stale_while_revalidate' => 600,
        ],
        'versions' => [
            'catalogs_geography' => 'discovery:v:catalogs:geography',
            'catalogs_categories' => 'discovery:v:catalogs:categories',
            'catalogs_taxonomy_terms' => 'discovery:v:catalogs:taxonomy_terms',
            'catalogs_languages' => 'discovery:v:catalogs:languages',
            'catalogs_banks' => 'discovery:v:catalogs:banks',
            'catalogs_references' => 'discovery:v:catalogs:references',
            'catalogs_titles' => 'discovery:v:catalogs:titles',
            'events_schedule' => 'discovery:v:events:schedule',
            'homepage_stats' => 'discovery:v:homepage:stats',
            'institutions_search' => 'discovery:v:institutions:search',
            'institutions_listing' => 'discovery:v:institutions:listing',
            'persons_search' => 'discovery:v:persons:search',
            'persons_listing' => 'discovery:v:persons:listing',
            'persons_featured' => 'discovery:v:persons:featured',
            'references_search' => 'discovery:v:references:search',
            'references_listing' => 'discovery:v:references:listing',
            'venues_search' => 'discovery:v:venues:search',
            'submission_events' => 'discovery:v:submission:events',
            'submission_institutions' => 'discovery:v:submission:institutions',
            'submission_persons' => 'discovery:v:submission:persons',
            'inspirations' => 'discovery:v:inspirations',
            'sitemap' => 'discovery:v:sitemap',
        ],
    ],

    'search' => [
        'driver' => env('DISCOVERY_SEARCH_DRIVER'),
        'result_limit' => 250,
        'fuzzy_candidate_limit' => 1500,
        'pg_trgm_threshold' => 0.25,
    ],
];
