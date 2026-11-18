<?php

declare(strict_types=1);

namespace App\Platform\Admin\Filament\Stats;

interface AdminStatProvider
{
    /**
     * @return iterable<AdminStat>
     */
    public function stats(): iterable;
}
