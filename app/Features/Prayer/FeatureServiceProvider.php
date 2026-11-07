<?php

declare(strict_types=1);

namespace App\Features\Prayer;

use App\Features\Prayer\EntryPoints\Commands\PrayerStatsCommand;
use App\Features\Prayer\EntryPoints\Commands\PrefetchPrayerTimes;
use App\Platform\FeatureModule;
use Illuminate\Support\Facades\Schedule;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/prayer.php', 'prayer');

        foreach (['BuildPrayerPreviewAction', 'ResolvePrayerAnchorAction', 'ResolvePrayerStartClockAction'] as $action) {
            $this->app->bind(__NAMESPACE__.'\\Api\\'.$action, __NAMESPACE__.'\\Actions\\'.$action);
        }

        foreach (['PrayerProviderRegistry', 'RamadanGate'] as $service) {
            $this->app->bind(__NAMESPACE__.'\\Api\\'.$service, __NAMESPACE__.'\\Services\\'.$service);
        }
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([PrefetchPrayerTimes::class, PrayerStatsCommand::class]);
            Schedule::command('app:prayer:prefetch')
                ->dailyAt('03:30')
                ->timezone('Asia/Kuala_Lumpur')
                ->name('prayer-prefetch')
                ->withoutOverlapping();
            Schedule::command('app:prayer:stats --alert-queue-depth=5000')
                ->hourly()
                ->timezone('UTC')
                ->name('prayer-queue-depth-alert')
                ->withoutOverlapping();
        }
    }
}
