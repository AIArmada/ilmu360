<?php

declare(strict_types=1);

namespace App\Filament\Pages;

class AdminDashboard extends PanelDashboard
{
    protected static bool $isDiscovered = false;

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';
}
