<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\ServiceProvider;
use ReflectionClass;

abstract class FeatureModule extends ServiceProvider
{
    public function boot(): void
    {
        $root = dirname((string) (new ReflectionClass($this))->getFileName());

        foreach (['web', 'api', 'console'] as $transport) {
            $routeFile = $root.'/Routes/'.$transport.'.php';
            if (is_file($routeFile)) {
                $this->loadRoutesFrom($routeFile);
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
