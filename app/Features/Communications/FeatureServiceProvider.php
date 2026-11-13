<?php

declare(strict_types=1);

namespace App\Features\Communications;

use App\Platform\FeatureModule;
use Illuminate\Support\Facades\Schedule;

final class FeatureServiceProvider extends FeatureModule
{
    public function boot(): void
    {
        parent::boot();

        if (! $this->app->runningInConsole()) {
            return;
        }

        Schedule::command('communications:dispatch-due')
            ->everyMinute()
            ->timezone('UTC')
            ->name('communications-dispatch-due')
            ->withoutOverlapping();
    }
}
