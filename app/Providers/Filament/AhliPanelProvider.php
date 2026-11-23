<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use AIArmada\FilamentEngagement\FilamentEngagementPlugin;
use AIArmada\FilamentEvents\FilamentEventsPlugin;
use AIArmada\FilamentOrganizations\FilamentOrganizationsPlugin;
use AIArmada\FilamentPersons\FilamentPersonsPlugin;
use AIArmada\FilamentSignals\Concerns\TracksSignalsPanel;
use App\Platform\Admin\EntryPoints\Filament\Pages\AhliDashboard;
use App\Platform\Admin\FilamentPageRegistry;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Admin\FilamentWidgetRegistry;
use App\Platform\Time\Http\Middleware\SetFilamentTimezone;
use App\Providers\Filament\Concerns\ResolvesPanelDomain;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AhliPanelProvider extends PanelProvider
{
    use ResolvesPanelDomain;
    use TracksSignalsPanel;

    public function panel(Panel $panel): Panel
    {
        $ahliDomain = $this->resolvePanelDomain('ahli');
        $registeredResources = $this->app->make(FilamentResourceRegistry::class)->all(FilamentResourceRegistry::PANEL_AHLI);
        $registeredPages = $this->app->make(FilamentPageRegistry::class)->all(FilamentPageRegistry::PANEL_AHLI);
        $registeredWidgets = $this->app->make(FilamentWidgetRegistry::class)->all(FilamentWidgetRegistry::PANEL_AHLI);

        return $this->trackSignalsForPanel($panel, 'ahli')
            ->id('ahli')
            ->domain($ahliDomain)
            ->path(filled($ahliDomain) ? '' : 'ahli')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandLogo(asset('images/logo-ilmu360.png'))
            ->brandLogoHeight('3rem')
            ->login()
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
            ])
            ->font('Outfit')
            ->maxContentWidth('full')
            ->resources($registeredResources)
            ->widgets($registeredWidgets)
            ->plugins([
                FilamentEventsPlugin::make(),
                FilamentOrganizationsPlugin::make(),
                FilamentPersonsPlugin::make(),
                FilamentEngagementPlugin::make(),
            ])
            ->pages([
                AhliDashboard::class,
                ...$registeredPages,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetFilamentTimezone::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->topNavigation()
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
