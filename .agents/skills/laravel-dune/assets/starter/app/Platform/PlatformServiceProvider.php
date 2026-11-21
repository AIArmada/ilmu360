<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\CacheCommand;
use App\Platform\Console\ClearCommand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $catalog = FeatureCatalog::load($this->app->basePath());

        $this->app->singleton(
            ExtensionRegistry::class,
            fn ($app): ExtensionRegistry => new ExtensionRegistry($app, $catalog['extensions'])
        );

        if ($this->discoveryMode() === 'auto') {
            foreach (array_keys($catalog['features']) as $feature) {
                $provider = "App\\Features\\{$feature}\\FeatureServiceProvider";
                if (! is_subclass_of($provider, FeatureModule::class)) {
                    throw new RuntimeException("{$provider} must extend ".FeatureModule::class.'.');
                }
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        $isProduction = $this->app->isProduction();

        Model::shouldBeStrict(! $isProduction);
        DB::prohibitDestructiveCommands($isProduction);
        Date::use(CarbonImmutable::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CacheCommand::class,
                ClearCommand::class,
            ]);

            if (method_exists($this, 'optimizes')) {
                $this->optimizes(
                    optimize: 'platform:cache',
                    clear: 'platform:clear',
                    key: 'platform',
                );
            }
        }
    }

    private function discoveryMode(): string
    {
        $configPath = $this->app->basePath('tools/architecture.json');
        if (! is_file($configPath)) {
            return 'auto';
        }

        $raw = file_get_contents($configPath);
        if ($raw === false) {
            return 'auto';
        }

        $config = json_decode($raw, true);

        return ($config['discovery'] ?? 'auto') === 'native' ? 'native' : 'auto';
    }
}
