<?php

declare(strict_types=1);

namespace App\Features\Prayer;

use App\Features\Prayer\Api\BuildPrayerPreviewAction;
use App\Features\Prayer\Api\PrayerInstitutionCatalog;
use App\Features\Prayer\Api\PrayerLocationResolver;
use App\Features\Prayer\Api\RamadanGate;
use App\Features\Prayer\EntryPoints\Commands\PrayerStatsCommand;
use App\Features\Prayer\EntryPoints\Commands\PrefetchPrayerTimes;
use App\Features\Prayer\Services\PrayerInstitutionCatalogService;
use App\Features\Prayer\Services\PrayerLocationResolverService;
use App\Platform\FeatureModule;
use Illuminate\Support\Facades\Schedule;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(PrayerLocationResolver::class, PrayerLocationResolverService::class);
        $this->app->bind(PrayerInstitutionCatalog::class, PrayerInstitutionCatalogService::class);
        $this->mergeConfigFrom(__DIR__.'/Config/prayer.php', 'prayer');

        $this->app->bind(BuildPrayerPreviewAction::class, __NAMESPACE__.'\\Actions\\BuildPrayerPreviewAction');

        foreach (['ResolvePrayerAnchorAction', 'ResolvePrayerStartClockAction'] as $action) {
            $this->app->bind(__NAMESPACE__.'\\Api\\'.$action, __NAMESPACE__.'\\Actions\\'.$action);
        }

        $this->app->bind(RamadanGate::class, __NAMESPACE__.'\\Services\\RamadanGate');
        $this->app->bind(__NAMESPACE__.'\\Api\\PrayerProviderRegistry', __NAMESPACE__.'\\Services\\PrayerProviderRegistry');
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
