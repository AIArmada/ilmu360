<?php

declare(strict_types=1);

namespace App\Platform\Cache\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Description('Inspect discovery cache configuration and active version keys')]
#[Signature('discovery:cache-status')]
final class DiscoveryCacheStatusCommand extends Command
{
    public function handle(): int
    {
        $enabled = (bool) config('discovery.cache.enabled', true);
        $configuredStore = config('discovery.cache.store');
        $activeStore = is_string($configuredStore) && $configuredStore !== ''
            ? $configuredStore
            : (string) config('cache.default');
        $configuredDriver = config('discovery.search.driver');
        $activeDriver = is_string($configuredDriver) && $configuredDriver !== ''
            ? $configuredDriver
            : (string) config('scout.driver');

        $this->components->twoColumnDetail('Cache Enabled', $enabled ? 'true' : 'false');
        $this->components->twoColumnDetail('Cache Store', $activeStore);
        $this->components->twoColumnDetail('Search Driver', $activeDriver);

        $store = is_string($configuredStore) && $configuredStore !== ''
            ? Cache::store($configuredStore)
            : Cache::store();

        $configuredVersions = config('discovery.cache.versions', []);
        $versions = is_array($configuredVersions) ? $configuredVersions : [];
        $rows = [];

        foreach ($versions as $name => $versionKey) {
            if (! is_string($versionKey) || $versionKey === '') {
                continue;
            }

            $current = $store->get($versionKey);
            $rows[] = [
                'name' => (string) $name,
                'key' => $versionKey,
                'version' => is_string($current) && $current !== '' ? $current : '<cold>',
            ];
        }

        $this->table(['Scope', 'Version Key', 'Current Version'], $rows);

        return self::SUCCESS;
    }
}
