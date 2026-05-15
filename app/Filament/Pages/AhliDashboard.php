<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Ahli\Widgets\PendingApprovalEventsWidget;

class AhliDashboard extends PanelDashboard
{
    protected static bool $isDiscovered = false;

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    /**
     * @return array<class-string>
     */
    #[\Override]
    public function getWidgets(): array
    {
        return [
            PendingApprovalEventsWidget::class,
        ];
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
