<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

abstract class PanelDashboard extends BaseDashboard
{
    // Filament reserves "/" for the panel home redirect, so custom dashboards need
    // a concrete page route to register a stable `pages.*-dashboard` route name.
    protected static string $routePath = '/dashboard';
}
