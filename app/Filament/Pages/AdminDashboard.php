<?php

declare(strict_types=1);

namespace App\Filament\Pages;

class AdminDashboard extends PanelDashboard
{
    #[\Override]
    protected static bool $isDiscovered = false;

    #[\Override]
    protected static ?string $title = 'Dashboard';

    #[\Override]
    protected static ?string $navigationLabel = 'Dashboard';
}
