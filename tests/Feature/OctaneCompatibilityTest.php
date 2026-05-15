<?php

use AIArmada\FilamentSignals\FilamentSignalsPlugin;
use AIArmada\FilamentSignals\Pages\PageViewsReport;
use AIArmada\FilamentSignals\Pages\SignalsDashboard;
use App\Observers\AuditedMediaObserver;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;

it('has octane reset listeners configured for long-lived workers', function () {
    $listeners = config('octane.listeners.'.OperationTerminated::class);

    expect($listeners)
        ->toBeArray()
        ->toContain(DisconnectFromDatabases::class)
        ->toContain(CollectGarbage::class);
});

it('enables permission reset listener in octane mode by default', function () {
    expect(config('permission.register_octane_reset_listener'))->toBeTrue();
});

it('registers octane commands', function () {
    $artisanCommands = collect(app(Kernel::class)->all());

    expect($artisanCommands->keys())
        ->toContain('octane:start')
        ->toContain('octane:stop')
        ->toContain('octane:status');
});

it('uses the package plugin with static config to include the signals dashboard in admin', function () {
    expect(config('filament-signals.features.dashboard'))->toBeTrue();

    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(new Panel);

    expect(collect($panel->getPlugins())->contains(
        static fn (mixed $plugin): bool => $plugin instanceof FilamentSignalsPlugin
    ))->toBeTrue()
        ->and($panel->getPages())->toContain(PageViewsReport::class)
        ->and($panel->getPages())->toContain(SignalsDashboard::class);
});

it('does not globally share blaze runtime state in the view factory', function () {
    expect(view()->getShared())->not->toHaveKey('__blaze');
});

it('stores audited media transient snapshots in weak maps', function () {
    $reflection = new ReflectionClass(AuditedMediaObserver::class);

    expect($reflection->getProperty('pendingUpdateSnapshots')->getType()?->getName())->toBe(WeakMap::class)
        ->and($reflection->getProperty('pendingDeleteSnapshots')->getType()?->getName())->toBe(WeakMap::class);
});
