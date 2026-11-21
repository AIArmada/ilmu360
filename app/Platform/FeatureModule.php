<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\ServiceProvider;
use ReflectionClass;

abstract class FeatureModule extends ServiceProvider
{
    public function boot(): void
    {
        FeatureCatalog::runtime($this->app->basePath());
        $root = dirname(new ReflectionClass($this)->getFileName());

        foreach (['web', 'api'] as $transport) {
            $path = $root.'/Routes/'.$transport.'.php';
            if (is_file($path)) {
                // Each route file declares its own middleware, prefix and name group.
                $this->loadRoutesFrom($path);
            }
        }

        if (is_dir($root.'/Views')) {
            $this->loadViewsFrom($root.'/Views', strtolower(basename($root)));
        }
        if (is_dir($root.'/Database/Migrations')) {
            $this->loadMigrationsFrom($root.'/Database/Migrations');
        }
    }
}
