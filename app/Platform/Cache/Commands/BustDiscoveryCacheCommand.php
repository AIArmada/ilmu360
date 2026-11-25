<?php

declare(strict_types=1);

namespace App\Platform\Cache\Commands;

use App\Shared\Cache\DiscoveryCache;
use App\Shared\Contracts\SearchHealthCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Bust versioned discovery caches by scope')]
#[Signature('discovery:cache-bust {--scope=all : Scope to bust (all, events, institutions, persons, references, venues, catalogs, stats, health, submission, sitemap)}')]
final class BustDiscoveryCacheCommand extends Command
{
    /** @var array<string, list<string>> */
    private const array SCOPE_MAP = [
        'events' => ['events_schedule'],
        'institutions' => ['institutions_search', 'institutions_listing', 'submission_institutions'],
        'persons' => ['persons_search', 'persons_listing', 'persons_featured', 'submission_persons'],
        'references' => ['references_search', 'references_listing', 'catalogs_references'],
        'venues' => ['venues_search'],
        'catalogs' => [
            'catalogs_geography',
            'catalogs_categories',
            'catalogs_taxonomy_terms',
            'catalogs_languages',
            'catalogs_banks',
            'catalogs_references',
            'catalogs_titles',
        ],
        'stats' => ['homepage_stats'],
        'submission' => ['submission_events', 'submission_institutions', 'submission_persons'],
        'sitemap' => ['sitemap'],
    ];

    public function handle(DiscoveryCache $cache, SearchHealthCheck $healthCheck): int
    {
        $scope = strtolower(trim((string) $this->option('scope')));
        $validScopes = ['all', ...array_keys(self::SCOPE_MAP), 'health'];

        if (! in_array($scope, $validScopes, true)) {
            $this->components->error(sprintf(
                'Invalid scope [%s]. Valid scopes: %s',
                $scope,
                implode(', ', $validScopes),
            ));

            return self::FAILURE;
        }

        $configuredVersions = config('discovery.cache.versions', []);
        $versions = is_array($configuredVersions) ? $configuredVersions : [];

        if ($scope === 'health' || $scope === 'all') {
            $healthCheck->bust();
            $this->components->twoColumnDetail('health', 'cleared');
        }

        if ($scope === 'health') {
            return self::SUCCESS;
        }

        $names = $scope === 'all'
            ? array_keys($versions)
            : self::SCOPE_MAP[$scope];

        foreach ($names as $name) {
            $versionKey = $versions[$name] ?? null;

            if (! is_string($versionKey) || $versionKey === '') {
                continue;
            }

            $newVersion = $cache->bump($versionKey);
            $this->components->twoColumnDetail("{$name} ({$versionKey})", $newVersion);
        }

        $this->components->info(sprintf('Busted discovery cache scope [%s].', $scope));

        return self::SUCCESS;
    }
}
