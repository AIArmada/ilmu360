<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\AhliDashboard;
use App\Providers\Filament\Concerns\ResolvesPanelDomain;
use App\Providers\Filament\Concerns\TracksSignalsPanel;
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

        return $this->trackSignalsForPanel($panel, 'ahli')
            ->id('ahli')
            ->domain($ahliDomain)
            ->path(filled($ahliDomain) ? '' : 'ahli')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandLogo(asset('images/milogo.webp'))
            ->brandLogoHeight('3rem')
            ->login()
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
            ])
            ->font('Outfit')
            ->maxContentWidth('full')
            ->discoverResources(in: app_path('Filament/Ahli/Resources'), for: 'App\Filament\Ahli\Resources')
            ->discoverWidgets(in: app_path('Filament/Ahli/Widgets'), for: 'App\Filament\Ahli\Widgets')
            ->pages([
                AhliDashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
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
