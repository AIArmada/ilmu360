<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

use Filament\Panel;
use Filament\View\PanelsRenderHook;

trait TracksSignalsPanel
{
    protected function trackSignalsForPanel(Panel $panel, string $panelId): Panel
    {
        return $panel->renderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => view('components.analytics.signals-tracker', ['surface' => $panelId])->render(),
        );
    }
}
