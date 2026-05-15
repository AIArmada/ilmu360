<?php

namespace App\Providers\Filament;

use AIArmada\FilamentAuthz\FilamentAuthzPlugin;
use AIArmada\FilamentSignals\FilamentSignalsPlugin;
use App\Filament\Pages\AdminDashboard;
use App\Providers\Filament\Concerns\ResolvesPanelDomain;
use App\Providers\Filament\Concerns\TracksSignalsPanel;
use App\Support\Authz\MemberRoleScopes;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Tapp\FilamentAuditing\FilamentAuditingPlugin;

class AdminPanelProvider extends PanelProvider
{
    use ResolvesPanelDomain;
    use TracksSignalsPanel;

    public function panel(Panel $panel): Panel
    {
        $adminDomain = $this->resolvePanelDomain('admin');
        $authzPlugin = FilamentAuthzPlugin::make()
            ->centralApp()
            ->userRoleScopeMode('global_only');

        if ($this->shouldRegisterDynamicRoleScopeOptions()) {
            $authzPlugin->roleScopeOptionsUsing(
                fn (): array => app(MemberRoleScopes::class)->roleResourceOptions()
            );
        }

        return $this->trackSignalsForPanel($panel, 'admin')
            ->default()
            ->id('admin')
            ->domain($adminDomain)
            ->path(filled($adminDomain) ? '' : 'admin')
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
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                AdminDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // AccountWidget::class,
                // FilamentInfoWidget::class,
            ])
            ->plugins([
                FilamentSignalsPlugin::make(),
                $authzPlugin,
                FilamentAuditingPlugin::make(),
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

    private function shouldRegisterDynamicRoleScopeOptions(): bool
    {
        if (! app()->runningInConsole()) {
            return true;
        }

        /** @var list<string> $arguments */
        $arguments = array_values(array_map(
            static fn (mixed $argument): string => (string) $argument,
            is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [],
        ));

        foreach ($arguments as $argument) {
            if (in_array($argument, ['config:cache', 'optimize'], true)) {
                return false;
            }
        }

        return true;
    }
}
