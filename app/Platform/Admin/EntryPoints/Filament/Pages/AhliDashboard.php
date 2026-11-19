<?php

declare(strict_types=1);

namespace App\Platform\Admin\EntryPoints\Filament\Pages;

use App\Platform\Admin\FilamentWidgetRegistry;

class AhliDashboard extends PanelDashboard
{
    #[\Override]
    protected static bool $isDiscovered = false;

    #[\Override]
    protected static ?string $title = 'Dashboard';

    #[\Override]
    protected static ?string $navigationLabel = 'Dashboard';

    /**
     * @return array<class-string>
     */
    #[\Override]
    public function getWidgets(): array
    {
        return app(FilamentWidgetRegistry::class)->all(FilamentWidgetRegistry::PANEL_AHLI);
    }

    /**
     * @return int|array<string, ?int>
     */
    #[\Override]
    public function getColumns(): int|array
    {
        return 1;
    }
}
