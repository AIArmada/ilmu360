<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

use Filament\Panel;
use Filament\View\PanelsRenderHook;

trait TracksSignalsPanel
{
    protected function trackSignalsForPanel(Panel $panel, string $panelId): Panel
    {
        $directive = sprintf("@signalsTracker(['properties' => ['surface' => '%s']])", $panelId);

        return $panel->renderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => app('blade.compiler')->compileString($directive),
        );
    }
}
